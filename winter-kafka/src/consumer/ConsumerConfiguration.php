<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\kafka\KafkaLogCallback;
use dev\winterframework\kafka\KafkaLogCallbackDefault;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\Conf as RdKafkaConf;
use RdKafka\KafkaConsumer;

class ConsumerConfiguration {
    use Wlf4p;

    private static array $defaults = [
        'metadata.broker.list' => null,
        'log_level' => LOG_INFO,

        'enable.auto.commit' => true,
        'auto.commit.interval.ms' => 100,
        'session.timeout.ms' => 9000,
        'statistics.interval.ms' => 30000,

        'auto.offset.reset' => 'earliest', // earliest, smallest, beginning, largest, latest, end, error

        /**
         * Maximum allowed time between calls to consume messages for high-level consumers.
         * If this interval is exceeded the consumer is considered failed and the group will
         * re-balance in order to reassign the partitions to another consumer group member.
         */
        'max.poll.interval.ms' => 10000,
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

        'message.max.bytes' => null,
        'max.message.bytes' => null,
        'fetch.max.bytes' => null,
        'receive.message.max.bytes' => null
    ];

    private array $config = [];
    private string $name = 'unnamed-group';
    private array $topics = [];
    private string $topic = '';
    private int $workerNum = 1;
    private string $workerClass = '';

    private int $retries = 1;
    private int $retryWaitMs = 300;

    private string $lagMonitor = ConsumerLagMonitor::class;
    private string $errorCallback = ConsumerErrorCallbackDefault::class;
    private string $logCallback = KafkaLogCallbackDefault::class;
    private string $rebalanceCallback = ConsumerRebalanceCallbackDefault::class;
    protected string $offsetCommitCallback = '';
    private array $transientExceptions = [];
    private ?RdKafkaConf $conf = null;
    protected KafkaConsumer $rawConsumer;

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
            if (property_exists($this, $key) && $key != 'config') {
                $this->$key = $value;
            } else {
                $this->config[$key] = $value;
            }
        }

        if ($this->topic) {
            $this->topics[] = $this->topic;
        }
    }

    /**
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
    }

    public function getRetries(): int {
        return ($this->retries <= 0) ? 1 : $this->retries;
    }

    public function getRetryWaitMs(): int {
        return $this->retryWaitMs;
    }

    /**
     * @return RdKafkaConf
     */
    public function getConf(): RdKafkaConf {
        if (!isset($this->conf)) {
            $this->buildConsumer();
        }

        return $this->conf;
    }

    protected function buildConsumer(): void {
        $this->conf = new RdKafkaConf();
        foreach ($this->config as $key => $value) {
            $this->conf->set($key, strval($value));
        }
        $this->conf->set('group.id', $this->name);

        if ($this->rebalanceCallback) {
            $cb = $this->rebalanceCallback;
            TypeAssert::objectOfIsA(
                $cb,
                ConsumerRebalanceCallback::class,
                'Kafka Consumer config "rebalanceCallback" must be of derived from ConsumerRebalanceCallback'
            );
            $this->conf->setRebalanceCb(new $cb($this, $this->ctx));
        }

        if ($this->lagMonitor) {
            $cb = $this->lagMonitor;
            TypeAssert::objectOfIsA(
                $cb,
                ConsumerStatisticsCallback::class,
                'Kafka Consumer config "lagMonitor" must be of derived from ConsumerStatisticsCallback'
            );
            $this->conf->setStatsCb(new $cb($this, $this->ctx));
        }

        if ($this->errorCallback) {
            $cb = $this->errorCallback;
            TypeAssert::objectOfIsA(
                $cb,
                ConsumerErrorCallback::class,
                'Kafka Consumer config "errorCallback" must be of derived from ConsumerErrorCallback'
            );
            $this->conf->setErrorCb(new $cb($this, $this->ctx));
        }

        if ($this->logCallback) {
            $cb = $this->logCallback;
            TypeAssert::objectOfIsA(
                $cb,
                KafkaLogCallback::class,
                'Kafka Consumer config "logCallback" must be of derived from KafkaLogCallback'
            );
            $this->conf->setLogCb(new $cb($this, $this->ctx));
        }

        if ($this->offsetCommitCallback) {
            $cb = $this->offsetCommitCallback;
            TypeAssert::objectOfIsA(
                $cb,
                ConsumerOffsetCommitCallback::class,
                'Kafka Consumer config "offsetCommitCallback" must be of derived from ConsumerOffsetCommitCallback'
            );
            $this->conf->setOffsetCommitCb(new $cb($this, $this->ctx));
        }

        $this->rawConsumer = new KafkaConsumer($this->conf);
    }

    /**
     * @return KafkaConsumer
     */
    public function getRawConsumer(): KafkaConsumer {
        if (!isset($this->conf)) {
            $this->buildConsumer();
        }
        return $this->rawConsumer;
    }

    public function unsetRawConsumer(): void {

        if (isset($this->rawConsumer)) {
            $this->rawConsumer->close();
        }

        unset($this->conf);
        unset($this->rawConsumer);
    }

    /**
     * @param RdKafkaConf|null $conf
     * @return ConsumerConfiguration
     */
    public function setConf(?RdKafkaConf $conf): ConsumerConfiguration {
        $this->conf = $conf;
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @param string $name
     * @return ConsumerConfiguration
     */
    public function setName(string $name): ConsumerConfiguration {
        $this->name = $name;
        return $this;
    }

    /**
     * @return array
     */
    public function getTopics(): array {
        return $this->topics;
    }

    /**
     * @param array $topics
     * @return ConsumerConfiguration
     */
    public function setTopics(array $topics): ConsumerConfiguration {
        $this->topics = $topics;
        return $this;
    }

    /**
     * @return int
     */
    public function getWorkerNum(): int {
        return $this->workerNum;
    }

    /**
     * @param int $workerNum
     * @return ConsumerConfiguration
     */
    public function setWorkerNum(int $workerNum): ConsumerConfiguration {
        $this->workerNum = $workerNum;
        return $this;
    }

    /**
     * @return string
     */
    public function getWorkerClass(): string {
        return $this->workerClass;
    }

    /**
     * @param string $workerClass
     * @return ConsumerConfiguration
     */
    public function setWorkerClass(string $workerClass): ConsumerConfiguration {
        $this->workerClass = $workerClass;
        return $this;
    }

    /**
     * @return array
     */
    public function getTransientExceptions(): array {
        return $this->transientExceptions;
    }

    /**
     * @param array $transientExceptions
     * @return ConsumerConfiguration
     */
    public function setTransientExceptions(array $transientExceptions): ConsumerConfiguration {
        $this->transientExceptions = $transientExceptions;
        return $this;
    }

}
