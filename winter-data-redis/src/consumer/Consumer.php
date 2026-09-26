<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\consumer;

use dev\winterframework\core\context\ApplicationContext;

interface Consumer {

    public function __construct(ApplicationContext $ctx, ConsumerConfiguration $config);

    public function getQueueName(): string;

    public function getConfiguration(): ConsumerConfiguration;

    public function consume(ConsumerRecords $records): void;

}
