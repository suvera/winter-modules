<?php
declare(strict_types=1);

namespace dev\winterframework\kafka;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use RdKafka\KafkaConsumer;

interface KafkaLogCallback {

    public function __construct(ConsumerConfiguration|ProducerConfiguration $config, ApplicationContext $ctx);

    public function __invoke(KafkaConsumer $kafka, int $level, string $facility, string $message): void;
}