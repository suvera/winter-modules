<?php

namespace dev\winterframework\kafka\producer;

use RdKafka\Conf as RdKafkaConf;
use RdKafka\Producer;
use RdKafka\Topic;

class ProducerConfiguration {

    private static $defaults = [
        'metadata.broker.list' => null,
        'bootstrap.servers' => null,
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

    ];

    private int $retries = 0;
    private string $name = '';
    private string $topic = '';

    private array $config = [];
    private RdKafkaConf $conf;
    private Producer $rawProducer;
    private Topic $topicObject;

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
            $this->buildProducer();
        }

        return $this->conf;
    }

    protected function buildProducer(): void {
        $this->conf = new RdKafkaConf();
        foreach ($this->config as $key => $value) {
            $this->conf->set($key, $value);
        }

        $this->rawProducer = new Producer($this->conf);
    }

    /**
     * @return Producer
     */
    public function getRawProducer(): Producer {
        if (!isset($this->conf)) {
            $this->buildProducer();
        }
        return $this->rawProducer;
    }

    /**
     * @return Topic
     */
    public function getTopicObject(): Topic {
        if (!isset($this->conf)) {
            $this->buildProducer();
            $this->topicObject = $this->rawProducer->newTopic($this->topic);
        }
        return $this->topicObject;
    }
    
    /**
     * @return int
     */
    public function getRetries(): int {
        return $this->retries;
    }

    /**
     * @param int $retries
     * @return ProducerConfiguration
     */
    public function setRetries(int $retries): ProducerConfiguration {
        $this->retries = $retries;
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


}