<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

interface Consumer {

    public function __construct(ConsumerConfiguration $config);

    public function getGroupName(): string;

    public function getConfiguration(): ConsumerConfiguration;

    public function consume(ConsumerRecords $records): void;

}