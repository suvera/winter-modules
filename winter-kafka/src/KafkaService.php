<?php

namespace dev\winterframework\kafka;

use dev\winterframework\kafka\consumer\ConsumerConfigurations;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\kafka\producer\ProducerConfigurations;

interface KafkaService {

    public function produce(
        string|ProducerConfiguration $producerOrName,
        mixed $message,
        mixed $key
    ): bool;

    public function produceAsync(
        string|ProducerConfiguration $producerOrName,
        mixed $message,
        mixed $key,
        callable $onSuccess = null,
        callable $onFailed = null
    ): void;

    public function getProducers(): ProducerConfigurations;

    public function getConsumers(): ConsumerConfigurations;

    public function beginConsume(): void;
}