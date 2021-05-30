<?php
namespace dev\winterframework\kafka\consumer;

use dev\winterframework\kafka\exception\KafkaRebalanceException;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\Conf as RdKafkaConf;
use RdKafka\KafkaConsumer;

class ConsumerConfiguration {
    use Wlf4p;

    private static $defaults = [
        'metadata.broker.list' => null,
        'bootstrap.servers' => null,

        'auto.commit.enable' => true,
        'auto.commit.interval.ms' => 100,

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

        /**
         * This defines the maximum and default time librdkafka will wait before
         * sending a batch of messages. Reducing this setting to e.g. 1ms ensures that
         * messages are sent ASAP, instead of being batched.
         */
        'queue.buffering.max.ms' => 1,

        'message.max.bytes' => null,
        'max.message.bytes' => null,
        'fetch.max.bytes' => null,
        'receive.message.max.bytes' => null
    ];

    private array $config = [];
    private string $name = '';
    private array $topics = [];
    private int $workerNum = 1;
    private string $workerClass = '';
    private array $transientExceptions = [];
    private RdKafkaConf $conf;
    protected KafkaConsumer $rawConsumer;

    /**
     * ConsumerConfiguration constructor.
     * @param array $config
     */
    public function __construct(array $config) {
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

        $this->config = $config;
    }

    /**
     * @return array
     */
    public function getConfig(): array {
        return $this->config;
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
            $this->conf->set($key, $value);
        }
        $this->conf->set('group.id', $this->name);

        $this->conf->setRebalanceCb(function (KafkaConsumer $kafka, mixed $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    self::logInfo("Assigning Kafka partitions " . json_encode($partitions));
                    $kafka->assign($partitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    self::logInfo("Revoking Kafka partitions " . json_encode($partitions));
                    $kafka->assign(null);
                    break;

                default:
                    throw new KafkaRebalanceException($err);
            }
        });

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
     * @param string $topics
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
