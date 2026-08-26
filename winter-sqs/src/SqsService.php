<?php
declare(strict_types=1);

namespace dev\winterframework\sqs;

use dev\winterframework\sqs\consumer\ConsumerConfigurations;

interface SqsService {

    /**
     * Send a message to an SQS queue through a named connection.
     */
    public function send(
        string $connectionName,
        string $queueName,
        mixed $message,
        ?array $messageAttributes = null,
        ?array $queueArgs = null
    ): bool;

    /**
     * Send a message asynchronously through a named connection.
     */
    public function sendAsync(
        string $connectionName,
        string $queueName,
        mixed $message,
        ?array $messageAttributes = null,
        ?array $queueArgs = null
    ): bool;

    /**
     * Create a new SQS queue. Returns the queue URL.
     */
    public function createQueue(
        string $connectionName,
        string $queueName,
        array $attributes = []
    ): string;

    /**
     * Delete an SQS queue by name.
     */
    public function deleteQueue(string $connectionName, string $queueName): bool;

    /**
     * Purge all messages from an SQS queue.
     */
    public function purgeQueue(string $connectionName, string $queueName): bool;

    /**
     * Resolve the queue URL for a given queue name.
     */
    public function getQueueUrl(string $connectionName, string $queueName): string;

    /**
     * List queues matching an optional prefix.
     */
    public function listQueues(string $connectionName, string $prefix = '', int $maxResults = 1000): array;

    /**
     * Retrieve attributes for a queue.
     */
    public function getQueueAttributes(
        string $connectionName,
        string $queueName,
        array $attributeNames = ['All']
    ): array;

    /**
     * Set attributes on a queue.
     */
    public function setQueueAttributes(
        string $connectionName,
        string $queueName,
        array $attributes = []
    ): bool;

    public function getConnections(): SqsConnections;

    public function getConsumers(): ConsumerConfigurations;

    /**
     * Start consuming messages from all registered consumers (blocking).
     */
    public function beginConsume(): void;

}