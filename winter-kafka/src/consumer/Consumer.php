<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\core\context\ApplicationContext;

interface Consumer {

    public function __construct(ApplicationContext $ctx, ConsumerConfiguration $config);

    public function getGroupName(): string;

    public function getConfiguration(): ConsumerConfiguration;

    public function consume(ConsumerRecords $records): void;

}