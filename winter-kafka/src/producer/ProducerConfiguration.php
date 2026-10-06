<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\kafka\producer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\kafka\KafkaLogCallback;
use dev\winterframework\kafka\KafkaLogCallbackDefault;
use dev\winterframework\kafka\KafkaUtil;
use dev\winterframework\kafka\exception\KafkaException;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\Conf as RdKafkaConf;
use RdKafka\Producer;
use RdKafka\ProducerTopic;
use Throwable;

class ProducerConfiguration {
    use Wlf4p;

    private const INTERNAL_PROPERTIES = ['config', 'ctx', 'conf', 'rawProducer', 'topicObject',
        'producerPid', 'transactionsInitialized', 'abandoned', 'defaults'];

    private static array $defaults = [
        'metadata.broker.list' => null,
        'log_level' => LOG_INFO,

        /**
         * Maximum Kafka protocol request message size.
         * Due to differing framing overhead between protocol versions the producer is unable to reliably enforce
         * a strict max message limit at produce time and may exceed the maximum size by one message
         * in protocol ProduceRequests, the broker will enforce the the topic's max.message.bytes limit
         */
        'message.max.bytes' => null,

        /**
         * librdkafka fetches the metadata for all topics of the cluster by default.
         * Setting topic.metadata.refresh.sparse to the string "true" makes sure that
         * librdkafka fetches only the topics he uses, and reduce the bandwidth a lot.
         */
        'topic.metadata.refresh.sparse' => true,
        'topic.metadata.refresh.interval.ms' => 600,

        /**
         * This setting allows librdkafka threads to terminate as soon as librdkafka is done with them.
         * This effectively allows your PHP processes / requests to terminate quickly.
         *
         * You need to set somewhere in your code:
         *      pcntl_sigprocmask(SIG_BLOCK, [SIGIO]);
         */
        'internal.termination.signal' => SIGIO,

        /**
         * This defines the maximum and default time librdkafka will wait before
         * sending a batch of messages. Reducing this setting to e.g. 1ms ensures that
         * messages are sent ASAP, instead of being batched.
         */
        'queue.buffering.max.ms' => 1,

        /**
         * 'retries' - Alias for 'message.send.max.retries' How many times to retry sending a failing Message
         * 'retry.backoff.ms' -The backoff time in milliseconds before retrying a protocol request.
         * Note: retrying may cause reordering unless 'enable.idempotence' is set to true.
         */
        'message.send.max.retries' => null,
        'retries' => null,
        'retry.backoff.ms' => null,

        /**
         * random             - random distribution
         * consistent         - CRC32 hash of key (Empty and NULL keys are mapped to single partition)
         * consistent_random  - CRC32 hash of key (Empty and NULL keys are randomly partitioned)
         */
        'partitioner' => 'consistent_random'
    ];

    private string $name = '';
    private string $topic = '';
    protected bool $transactionEnabled = false;
    private string $logCallback = KafkaLogCallbackDefault::class;

    private array $config = [];
    private RdKafkaConf $conf;
    private Producer $rawProducer;
    private ProducerTopic $topicObject;
    private int $producerPid = 0;
    private bool $transactionsInitialized = false;

    /** Producers left behind by fork()/fatal errors; kept so destructors never run. */
    private static array $abandoned = [];

    /**
     * ConsumerConfiguration constructor.
     * @param array $config
     */
    public function __construct(array $config, protected ApplicationContext $ctx) {
        foreach (self::$defaults as $key => $value) {
            if (isset($value)) {
                $this->config[$key] = $value;
            }
        }

        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && !in_array($key, self::INTERNAL_PROPERTIES, true)) {
                $this->$key = $value;
            } else {
                $this->config[$key] = $value;
            }
        }
    }

    /**
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    public function getConfigVal(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    /**
     * @return RdKafkaConf
     */
    public function getConf(): RdKafkaConf {
        $this->ensureProducer();
        return $this->conf;
    }

    /**
     * Build the producer on first use in this process. A producer inherited
     * through fork() (Swoole forks workers after modules boot) has no
     * librdkafka threads in the child: it is abandoned, never destroyed
     * (its destructor would wait on those threads), and rebuilt.
     */
    protected function ensureProducer(): void {
        if (isset($this->conf) && $this->producerPid === getmypid()) {
            return;
        }
        if (isset($this->rawProducer)) {
            self::$abandoned[] = $this->rawProducer;
        }
        $this->buildProducer();
    }

    protected function buildProducer(): void {
        $conf = new RdKafkaConf();
        foreach ($this->config as $key => $value) {
            if ($key === 'transactional.id') {
                continue;
            }
            $conf->set($key, strval($value));
        }

        if ($this->isTransactionEnabled()) {
            KafkaUtil::logDebug('kafka transactions enabled');
            $conf->set('transactional.id', $this->buildTransactionalId());
        }
        if ($this->logCallback && is_a($this->logCallback, KafkaLogCallback::class, true)) {
            $cb = $this->logCallback;
            $conf->setLogCb(new $cb($this, $this->ctx));
        }

        $this->conf = $conf;
        $this->rawProducer = new Producer($conf);
        unset($this->topicObject);
        if ($this->topic !== '') {
            $this->topicObject = $this->rawProducer->newTopic($this->topic);
        }
        $this->producerPid = getmypid();
        $this->transactionsInitialized = false;
    }

    /**
     * A transactional.id must belong to exactly one live producer: Kafka
     * fences the older one whenever another producer initialises the same
     * id. Every Swoole worker has its own producer, so the id is the
     * configured prefix (default "TRANSACTION-<name>") plus host and worker.
     * The Swoole worker id is stable across worker restarts, which lets a
     * restarted worker fence its predecessor's unfinished transaction.
     */
    protected function buildTransactionalId(): string {
        $prefix = strval($this->config['transactional.id'] ?? ('TRANSACTION-' . $this->getName()));

        $worker = 'p' . getmypid();
        try {
            if ($this->ctx->hasBeanByClass(WinterServer::class)) {
                /** @var WinterServer $wServer */
                $wServer = $this->ctx->beanByClass(WinterServer::class);
                $workerId = $wServer->getServer()->worker_id ?? -1;
                if (is_int($workerId) && $workerId >= 0) {
                    $worker = 'w' . $workerId;
                }
            }
        } catch (Throwable) {
        }

        return $prefix . '-' . gethostname() . '-' . $worker;
    }

    /**
     * initTransactions() may run only once per producer instance.
     */
    public function ensureTransactionsInitialized(int $timeoutMs): void {
        $this->ensureProducer();
        if ($this->transactionsInitialized) {
            return;
        }
        $this->rawProducer->initTransactions($timeoutMs);
        $this->transactionsInitialized = true;
    }

    /**
     * Drop the producer after a fatal error; the next send builds a new one.
     */
    public function resetProducer(): void {
        if (isset($this->rawProducer) && $this->producerPid !== getmypid()) {
            self::$abandoned[] = $this->rawProducer;
        }
        unset($this->conf, $this->rawProducer, $this->topicObject);
        $this->producerPid = 0;
        $this->transactionsInitialized = false;
    }

    /**
     * @return Producer
     */
    public function getRawProducer(): Producer {
        $this->ensureProducer();
        return $this->rawProducer;
    }

    /**
     * @return ProducerTopic
     */
    public function getTopicObject(): ProducerTopic {
        $this->ensureProducer();
        if (!isset($this->topicObject)) {
            throw new KafkaException('Kafka producer "' . $this->getName() . '" has no topic configured');
        }
        return $this->topicObject;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ProducerConfiguration
     */
    public function setName(string $name): ProducerConfiguration {
        $this->name = $name;
        return $this;
    }

    /**
     * @return string
     */
    public function getTopic(): string {
        return $this->topic;
    }

    /**
     * @param string $topic
     * @return ProducerConfiguration
     */
    public function setTopic(string $topic): ProducerConfiguration {
        $this->topic = $topic;
        return $this;
    }

    /**
     * @return bool
     */
    public function isTransactionEnabled(): bool {
        return $this->transactionEnabled;
    }

}