<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use RdKafka\KafkaConsumer;

interface ConsumerStatistics {

    public function __construct(ConsumerConfiguration $config);

    public function __invoke(KafkaConsumer $kafka, string $json, int $json_len): void;
}