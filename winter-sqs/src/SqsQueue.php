<?php
declare(strict_types=1);

namespace dev\winterframework\sqs;

use dev\winterframework\sqs\consumer\ConsumerConfiguration;
use dev\winterframework\sqs\consumer\ConsumerRecord;
use dev\winterframework\type\Queue;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class SqsQueue implements Queue {
    use Wlf4p;

    public function __construct(
        protected SqsConnection $connection,
        protected string $queueName,
        protected ConsumerConfiguration $consumer
    ) {
    }

    /**
     * @throws
     */
    public function add(mixed $item, int $timeoutMs = 0): bool {
        SqsUtil::sendMessage($this->connection, $this->queueName, $item);

        return true;
    }

    public function poll(int $timeoutMs = 0): mixed {
        $waitTimeSeconds = intval($this->consumer->getConfigVal('waitTimeSeconds', 20));

        try {
            $client = $this->connection->getRawClient();
            $queueUrl = $this->consumer->resolveQueueUrl();

            $result = $client->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => 1,
                'WaitTimeSeconds' => $waitTimeSeconds,
            ]);

            $messages = $result['Messages'] ?? [];
            if (!$messages) {
                return null;
            }

            $record = ConsumerRecord::fromResult($messages[0], $this->consumer->getName());

            $client->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $record->getReceiptHandle(),
            ]);

            return $record->getValue();
        } catch (Throwable $e) {
            self::logException($e);
        }

        return null;
    }

    public function isUnbounded(): bool {
        return true;
    }

    public function size(): int {
        return PHP_INT_MAX;
    }

    public function isCountable(): bool {
        return false;
    }

}