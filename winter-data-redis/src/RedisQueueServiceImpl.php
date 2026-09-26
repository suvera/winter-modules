<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\data\redis;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\data\redis\consumer\ConsumerConfiguration;
use dev\winterframework\data\redis\consumer\ConsumerConfigurations;
use dev\winterframework\data\redis\exception\RedisQueueException;
use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\data\redis\util\RedisUtil;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\util\log\Wlf4p;

class RedisQueueServiceImpl implements RedisQueueService {
    use Wlf4p;

    protected ConsumerConfigurations $consumers;

    #[Autowired]
    private WinterServer $wServer;

    #[Autowired]
    private ApplicationContext $appCtx;

    private bool $consumerStarted = false;

    public function __construct() {
        $this->consumers = new ConsumerConfigurations();
    }

    // ------------------------------------------------------------------------
    //  SEND
    // ------------------------------------------------------------------------

    public function send(
        string $consumerOrStream,
        mixed $message,
        array $fields = []
    ): string {
        $config = $this->resolveConsumer($consumerOrStream);
        $redis = $this->resolveRedis($config, $consumerOrStream);
        $stream = $config?->getStream() ?? $consumerOrStream;

        $payload = is_string($message)
            ? $message
            : json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $entry = array_merge(
            [
                'payload' => strval($payload),
                'createdAt' => strval(intval(microtime(true) * 1000)),
            ],
            $fields
        );

        $trimMaxLen = $config?->getTrimMaxLen() ?? 0;
        if ($trimMaxLen > 0) {
            $id = $redis->xadd($stream, '*', $entry, $trimMaxLen, true);
        } else {
            $id = $redis->xadd($stream, '*', $entry);
        }

        return self::normalizeEntryId($id, $stream, self::lastError($redis));
    }

    protected static function lastError(PhpRedisAbstractTemplate $redis): string {
        try {
            $err = $redis->getLastError();
            return is_string($err) ? $err : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * XADD normally returns the entry id string, but template backends and
     * client versions differ (array/false seen in the wild): normalize to a
     * string so callers always get a usable id.
     */
    protected static function normalizeEntryId(mixed $id, string $stream, string $lastError = ''): string {
        if (is_array($id)) {
            $id = reset($id);
        }

        if (is_string($id) && $id !== '') {
            return $id;
        }

        if (is_scalar($id) && strval($id) !== '') {
            return strval($id);
        }

        self::logError('XADD on stream ' . $stream . ' returned unexpected value: '
            . json_encode($id) . ' lastError: ' . $lastError);

        return '';
    }

    public function sendAsync(
        string $consumerOrStream,
        mixed $message,
        array $fields = []
    ): string {
        return $this->send($consumerOrStream, $message, $fields);
    }

    // ------------------------------------------------------------------------
    //  STREAM MANAGEMENT
    // ------------------------------------------------------------------------

    public function createConsumerGroup(string $consumerOrStream): void {
        $config = $this->resolveConsumer($consumerOrStream);
        if ($config) {
            $config->ensureConsumerGroup();
            return;
        }

        RedisUtil::getRedisBean($this->appCtx)->xgroup(
            'CREATE',
            $consumerOrStream,
            $consumerOrStream . '-group',
            '$',
            true
        );
    }

    public function queueLength(string $consumerOrStream): int {
        $config = $this->resolveConsumer($consumerOrStream);
        $redis = $this->resolveRedis($config, $consumerOrStream);
        $stream = $config?->getStream() ?? $consumerOrStream;

        return intval($redis->xlen($stream));
    }

    public function trimStream(string $consumerOrStream, int $maxLen): int {
        $config = $this->resolveConsumer($consumerOrStream);
        $redis = $this->resolveRedis($config, $consumerOrStream);
        $stream = $config?->getStream() ?? $consumerOrStream;

        return intval($redis->xtrim($stream, $maxLen, true));
    }

    // ------------------------------------------------------------------------
    //  CONSUMERS
    // ------------------------------------------------------------------------

    public function getConsumers(): ConsumerConfigurations {
        return $this->consumers;
    }

    public function addConsumer(ConsumerConfiguration $config): void {
        self::logInfo('Redis queue consumer added: ' . $config->getName()
            . ', workerClass: ' . $config->getWorkerClass()
            . ', workerNum: ' . $config->getWorkerNum()
            . ', stream: ' . $config->getStream()
            . ', group: ' . $config->getGroup());
        $this->consumers[] = $config;
    }

    protected function startConsumer(ConsumerConfiguration $consumer, int $i): void {
        self::logInfo('Starting Redis queue consumer worker: ' . $consumer->getName()
            . ', workerId: ' . ($i + 1)
            . ', workerClass: ' . $consumer->getWorkerClass());
        $ps = new RedisQueueWorkerProcess($this->wServer, $this->appCtx, $consumer, $i + 1);
        $this->wServer->addProcess($ps);
    }

    public function beginConsume(): void {
        self::logInfo('beginConsume() called, consumerStarted=' . ($this->consumerStarted ? 'true' : 'false')
            . ', consumers count=' . $this->consumers->count());

        if ($this->consumerStarted) {
            self::logInfo('beginConsume() already started, skipping');
            return;
        }

        foreach ($this->consumers as $consumer) {
            /** @var ConsumerConfiguration $consumer */
            $workerNum = $consumer->getWorkerNum();
            self::logInfo('beginConsume() processing consumer: ' . $consumer->getName()
                . ', workerNum=' . $workerNum);
            for ($i = 0; $i < $workerNum; $i++) {
                $this->startConsumer($consumer, $i);
            }
        }

        $this->consumerStarted = true;
        self::logInfo('beginConsume() completed, consumerStarted set to true');
    }

    // ------------------------------------------------------------------------
    //  INTERNAL HELPERS
    // ------------------------------------------------------------------------

    protected function resolveConsumer(string $name): ?ConsumerConfiguration {
        if (isset($this->consumers[$name])) {
            return $this->consumers[$name];
        }

        foreach ($this->consumers as $consumer) {
            /** @var ConsumerConfiguration $consumer */
            if ($consumer->getStream() === $name) {
                return $consumer;
            }
        }

        return null;
    }

    protected function resolveRedis(
        ?ConsumerConfiguration $config,
        string $fallback
    ): PhpRedisAbstractTemplate {
        if ($config) {
            return $config->getRedis();
        }

        if ($this->appCtx->hasBeanByName($fallback)) {
            return $this->appCtx->beanByName($fallback);
        }

        try {
            return RedisUtil::getRedisBean($this->appCtx);
        } catch (\Throwable $e) {
            throw new RedisQueueException(
                'No Redis queue consumer "' . $fallback . '" and no default redis bean found', 0, $e
            );
        }
    }

}
