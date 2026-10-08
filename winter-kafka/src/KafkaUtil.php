<?php
declare(strict_types=1);


namespace dev\winterframework\kafka;


use dev\winterframework\kafka\exception\KafkaException;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\KafkaErrorException as RdKafkaException;
use RdKafka\TopicPartition;
use Throwable;

class KafkaUtil {
    use Wlf4p;

    public static function toPartitionsString(?array $partitions = null): string {
        $parts = '';
        if (is_null($partitions)) {
            return $parts;
        }

        foreach ($partitions as $partition) {
            /** @var TopicPartition $partition */
            if (!empty($parts)) {
                $parts .= ', ';
            }

            $parts .= $partition->getTopic() . '-' . $partition->getPartition();
        }

        return $parts;
    }

    public static function sendMessage(
        ProducerConfiguration $producer,
        mixed $message,
        mixed $key,
        ?callable $onSuccess = null,
        ?callable $onFailed = null
    ): void {
        $success = false;
        $exception = null;
        $timeoutMs = 10000;
        $trial = 0;
        $maxTries = $producer->getConfigVal('retries', 3);
        $maxTries = intval($maxTries);
        if ($maxTries <= 0) {
            $maxTries = 3;
        }

        while ($trial < $maxTries) {
            $trial++;
            try {
                self::doSendMessage($producer, $message, $key, $timeoutMs);
                $success = true;
                break;
            } /** @noinspection PhpRedundantCatchClauseInspection */
            catch (RdKafkaException $ex) {
                if ($ex->isRetriable()) {
                    continue;
                }
                self::logException($ex);
                $exception = $ex;
                break;
            } catch (Throwable $e) {
                self::logException($e);
                $exception = $e;
                break;
            }
        }

        if ($success) {
            if ($onSuccess != null) {
                $onSuccess($producer, $message, $key);
            }
        } else {
            $producer->getRawProducer()->purge(RD_KAFKA_PURGE_F_QUEUE);

            if ($onFailed != null) {
                $onFailed($producer->getName(), $message, $key);
            }

            throw new KafkaException('Could not produce message', 0, $exception);
        }
    }

    public static function doSendMessage(
        ProducerConfiguration $producer,
        mixed $message,
        mixed $key,
        int $timeoutMs
    ): void {
        $topic = $producer->getTopicObject();

        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
        $producer->getRawProducer()->poll(0);

        $result = null;
        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $producer->getRawProducer()->flush($timeoutMs);
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }

        if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
            self::logError('Was unable to flush to kafka, messages might be lost!');
        }
    }

    /**
     * @throws
     */
    public static function sendMessageInTransaction(
        ProducerConfiguration $producer,
        mixed $message,
        mixed $key,
        ?callable $onSuccess = null,
        ?callable $onFailed = null
    ): void {
        $timeoutMs = 10000;

        $success = false;
        $trial = 0;
        $maxTries = intval($producer->getConfigVal('retries', 3));
        if ($maxTries <= 0) {
            $maxTries = 3;
        }
        $exception = null;

        while ($trial < $maxTries) {
            $trial++;
            try {
                self::doSendMessageInTransaction($producer, $message, $key, $timeoutMs);
                $success = true;
                break;
            } /** @noinspection PhpRedundantCatchClauseInspection */
            catch (RdKafkaException $ex) {
                $exception = $ex;
                if ($ex->isFatal()) {
                    // Fenced or otherwise unusable: a fresh producer re-runs
                    // initTransactions() on the next attempt.
                    self::logException($ex);
                    $producer->resetProducer();
                    continue;
                }
                if ($ex->isRetriable()) {
                    continue;
                }
                self::logException($ex);
                break;
            } catch (Throwable $e) {
                self::logException($e);
                $exception = $e;
                break;
            }
        }

        if ($success) {
            if ($onSuccess != null) {
                $onSuccess($producer, $message, $key);
            }
            return;
        }

        if ($onFailed != null) {
            $onFailed($producer->getName(), $message, $key);
        }
        throw new KafkaException('Could not produce message in a transaction', 0, $exception);
    }

    /**
     * One transaction for one message. initTransactions() runs once per
     * producer; a failure after beginTransaction() aborts that transaction
     * (unless the producer is fatally broken) before rethrowing.
     */
    public static function doSendMessageInTransaction(
        ProducerConfiguration $producer,
        mixed $message,
        mixed $key,
        int $timeoutMs
    ): void {
        $producer->ensureTransactionsInitialized($timeoutMs);
        $topic = $producer->getTopicObject();
        $rawProducer = $producer->getRawProducer();

        $rawProducer->beginTransaction();
        try {
            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
            $rawProducer->poll(0);
            $rawProducer->commitTransaction($timeoutMs);
        } catch (Throwable $e) {
            if (!($e instanceof RdKafkaException && $e->isFatal())) {
                try {
                    $rawProducer->abortTransaction($timeoutMs);
                } catch (Throwable $abortEx) {
                    self::logException($abortEx);
                }
            }
            throw $e;
        }
    }
}
