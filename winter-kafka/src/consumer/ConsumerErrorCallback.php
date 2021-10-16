<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\core\context\ApplicationContext;
use RdKafka\KafkaConsumer;

interface ConsumerErrorCallback {

    public function __construct(ConsumerConfiguration $config, ApplicationContext $ctx);

    public function __invoke(KafkaConsumer $kafka, int $err, string $reason): void;
}