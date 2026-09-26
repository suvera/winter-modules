<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis;

use dev\winterframework\data\redis\consumer\ConsumerConfiguration;
use dev\winterframework\data\redis\consumer\ConsumerConfigurations;

interface RedisQueueService {

    /**
     * Append a message to a stream. $consumerOrStream is a consumer name first,
     * then a raw stream name. Returns the stream entry id.
     */
    public function send(
        string $consumerOrStream,
        mixed $message,
        array $fields = []
    ): string;

    /**
     * Fire-and-forget send (same durability as send; kept for SQS parity).
     */
    public function sendAsync(
        string $consumerOrStream,
        mixed $message,
        array $fields = []
    ): string;

    /**
     * Create the consumer group for a stream (MKSTREAM). No-op if it exists.
     */
    public function createConsumerGroup(string $consumerOrStream): void;

    /**
     * Stream length (XLEN).
     */
    public function queueLength(string $consumerOrStream): int;

    /**
     * Trim a stream to ~$maxLen entries (XTRIM ~).
     */
    public function trimStream(string $consumerOrStream, int $maxLen): int;

    public function getConsumers(): ConsumerConfigurations;

    public function addConsumer(ConsumerConfiguration $config): void;

    /**
     * Start consuming messages from all registered consumers (spawns workers).
     */
    public function beginConsume(): void;

}
