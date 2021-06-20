<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\KafkaQueue;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\type\Queue;

class TaskQueueKafka extends TaskQueueAbstract {

    protected function buildTaskQueue(): Queue {
        if (!extension_loaded('rdkafka')) {
            throw new DtceException("KafkaModule requires *rdkafka* extension in PHP runtime");
        }

        if (!isset($this->taskDef['queue.kafka.bootstrap.servers'])) {
            throw new DtceException("DTCE Task must have 'queue.kafka.bootstrap.servers' configured");
        }

        if (!isset($this->taskDef['queue.kafka.topic'])) {
            throw new DtceException("DTCE Task must have 'queue.kafka.topic' configured");
        }

        $consumerConfig = [
            'metadata.broker.list' => $this->taskDef['queue.kafka.bootstrap.servers'],
        ];

        $producerConfig = [
            'metadata.broker.list' => $this->taskDef['queue.kafka.bootstrap.servers'],
        ];

        if (isset($this->taskDef['queue.kafka.consumer'][0])) {
            $consumerConfig = array_merge($consumerConfig, $this->taskDef['queue.kafka.consumer'][0]);
        }

        if (isset($this->taskDef['queue.kafka.producer'][0])) {
            $producerConfig = array_merge($producerConfig, $this->taskDef['queue.kafka.producer'][0]);
        }

        $producer = new ProducerConfiguration($producerConfig);
        $consumer = new ConsumerConfiguration($consumerConfig);

        return new KafkaQueue($consumer, $producer);
    }

}