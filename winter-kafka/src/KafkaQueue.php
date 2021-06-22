<?php
declare(strict_types=1);

namespace dev\winterframework\kafka;

use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\type\Queue;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;
use Throwable;

class KafkaQueue implements Queue {
    use Wlf4p;

    public function __construct(
        protected ConsumerConfiguration $consumer,
        protected ProducerConfiguration $producer
    ) {
    }

    public function add(mixed $item, int $timeoutMs = 0): bool {
        $key = Uuid::uuid4()->toString();
        if ($this->producer->isTransactionEnabled()) {
            KafkaUtil::sendMessageInTransaction($this->producer, $item, $key);
        } else {
            KafkaUtil::sendMessage($this->producer, $item, $key);
        }
        return true;
    }

    public function poll(int $timeoutMs = 0): mixed {
        $topics = $this->consumer->getTopics();
        if (!$topics) {
            self::logInfo('No topics found for consumer ' . $this->consumer->getName());
            return null;
        }

        if ($timeoutMs <= 0) {
            $timeoutMs = 120 * 1000;
        }

        try {
            $this->consumer->getRawConsumer()->subscribe($topics);
        } catch (Throwable $e) {
            self::logException($e);
            return null;
        }

        self::logInfo(" [DTCE] Kafka Queue subscribed. '" . $this->consumer->getName()
            . ', topics: ' . json_encode($this->consumer->getTopics()));

        try {
            $message = $this->consumer->getRawConsumer()->consume($timeoutMs);
        } catch (Throwable $e) {
            self::logException($e);
            return null;
        }

        switch ($message->err) {

            case RD_KAFKA_RESP_ERR_NO_ERROR:
                return $message->payload;

            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                self::logDebug('No more kafka messages; will wait for more');
                break;

            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                self::logDebug('Kafka consumer TCP connection Timed out, re-listening!');
                break;

            case RD_KAFKA_RESP_ERR_UNKNOWN_TOPIC_OR_PART:
            case RD_KAFKA_RESP_ERR__UNKNOWN_TOPIC:
                $err = 'Kafka error ' . $message->err . ': ' . $message->errstr();
                $err .= ', Consumer: ' . $this->consumer->getName()
                    . ', Topics: ' . json_encode($this->consumer->getTopics());
                self::logError($err);
                break;

            default:
                self::logError('Kafka error ' . $message->err . ': ' . $message->errstr());
                break;
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