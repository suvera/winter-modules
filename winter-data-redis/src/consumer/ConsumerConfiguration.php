<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\data\redis\consumer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\data\redis\util\RedisUtil;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class ConsumerConfiguration {
    use Wlf4p;

    private static array $defaults = [
        'blockMs' => 5000,
        'batchSize' => 10,
        'pollIntervalMs' => 0,
        'claimIdleMs' => 30000,
        'reclaimIntervalMs' => 5000,
        'maxDeliveries' => 5,
    ];

    private string $name = '';
    private string $redis = '';
    private string $stream = '';
    private string $group = '';
    private int $workerNum = 1;
    private string $workerClass = '';
    private int $retries = 1;
    private int $retryWaitMs = 300;
    private array $transientExceptions = [];
    private string $deadLetterStream = '';
    private bool $autoCreateGroup = true;
    private int $trimMaxLen = 0;

    private array $config = [];
    private ?PhpRedisAbstractTemplate $redisTpl = null;

    /**
     * ConsumerConfiguration constructor.
     */
    public function __construct(array $config, protected ApplicationContext $ctx) {
        foreach (self::$defaults as $key => $value) {
            if (isset($value)) {
                $this->config[$key] = $value;
            }
        }

        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && !in_array($key, ['config', 'ctx', 'redisTpl'], true)) {
                $this->$key = $value;
            } else {
                $this->config[$key] = $value;
            }
        }

        if (!$this->stream) {
            $this->stream = $this->name;
        }

        if (!$this->group) {
            $this->group = $this->stream . '-group';
        }
    }

    public function getConfig(): array {
        return $this->config;
    }

    public function getConfigVal(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    public function getRedis(): PhpRedisAbstractTemplate {
        if (!isset($this->redisTpl)) {
            $this->redisTpl = $this->redis
                ? $this->ctx->beanByName($this->redis)
                : RedisUtil::getRedisBean($this->ctx);
        }

        return $this->redisTpl;
    }

    /**
     * Create the consumer group if missing (MKSTREAM creates the stream too).
     * Safe to call from every worker: BUSYGROUP (already exists) is ignored.
     *
     * The group starts at "0", not "$": entries sent before the first worker
     * created the group (e.g. by a web worker right after boot) must still
     * be delivered.
     */
    public function ensureConsumerGroup(): void {
        if (!$this->autoCreateGroup) {
            return;
        }

        try {
            $this->getRedis()->xgroup('CREATE', $this->stream, $this->group, '0', true);
        } catch (Throwable $e) {
            if (stripos($e->getMessage(), 'BUSYGROUP') === false) {
                throw $e;
            }
        }
    }

    public function getRetries(): int {
        return ($this->retries <= 0) ? 1 : $this->retries;
    }

    public function getRetryWaitMs(): int {
        return $this->retryWaitMs;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): ConsumerConfiguration {
        $this->name = $name;
        return $this;
    }

    public function getRedisBeanName(): string {
        return $this->redis;
    }

    public function setRedisBeanName(string $redis): ConsumerConfiguration {
        $this->redis = $redis;
        $this->redisTpl = null;
        return $this;
    }

    public function getStream(): string {
        return $this->stream;
    }

    public function setStream(string $stream): ConsumerConfiguration {
        $this->stream = $stream;
        return $this;
    }

    public function getGroup(): string {
        return $this->group;
    }

    public function setGroup(string $group): ConsumerConfiguration {
        $this->group = $group;
        return $this;
    }

    public function getWorkerNum(): int {
        return $this->workerNum;
    }

    public function setWorkerNum(int $workerNum): ConsumerConfiguration {
        $this->workerNum = $workerNum;
        return $this;
    }

    public function getWorkerClass(): string {
        return $this->workerClass;
    }

    public function setWorkerClass(string $workerClass): ConsumerConfiguration {
        $this->workerClass = $workerClass;
        return $this;
    }

    public function getTransientExceptions(): array {
        return $this->transientExceptions;
    }

    public function setTransientExceptions(array $transientExceptions): ConsumerConfiguration {
        $this->transientExceptions = $transientExceptions;
        return $this;
    }

    public function getDeadLetterStream(): string {
        return $this->deadLetterStream;
    }

    public function setDeadLetterStream(string $deadLetterStream): ConsumerConfiguration {
        $this->deadLetterStream = $deadLetterStream;
        return $this;
    }

    public function getBlockMs(): int {
        return intval($this->getConfigVal('blockMs', 5000));
    }

    public function getBatchSize(): int {
        return intval($this->getConfigVal('batchSize', 10));
    }

    public function getClaimIdleMs(): int {
        return intval($this->getConfigVal('claimIdleMs', 30000));
    }

    public function getReclaimIntervalMs(): int {
        return intval($this->getConfigVal('reclaimIntervalMs', 5000));
    }

    public function getMaxDeliveries(): int {
        return intval($this->getConfigVal('maxDeliveries', 5));
    }

    public function getTrimMaxLen(): int {
        return $this->trimMaxLen;
    }

    public function setTrimMaxLen(int $trimMaxLen): ConsumerConfiguration {
        $this->trimMaxLen = $trimMaxLen;
        return $this;
    }

}
