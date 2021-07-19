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

    public static function toPartitionsString(array $partitions = null): string {
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

    /** @noinspection PhpUnusedParameterInspection */
    public static function log(mixed $kafka, int $level, string $facility, string $message) {
        switch ($level) {
            case LOG_DEBUG:
                self::logDebug($facility . ' ' . $message);
                break;

            case LOG_INFO:
                self::logInfo($facility . ' ' . $message);
                break;

            case LOG_NOTICE:
                self::logNotice($facility . ' ' . $message);
                break;

            case LOG_WARNING:
                self::logWarning($facility . ' ' . $message);
                break;

            case LOG_ERR:
                self::logError($facility . ' ' . $message);
                break;

            case LOG_CRIT:
                self::logCritical($facility . ' ' . $message);
                break;

            case LOG_ALERT:
                self::logAlert($facility . ' ' . $message);
                break;

            default:
                self::logEmergency($facility . ' ' . $message);
                break;
        }
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

        self::logInfo("messaged produced to " . $topic->getName());
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
        $maxTries = $producer->getConfigVal('retries', 3);
        $maxTries = intval($maxTries);
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
                try {
                    $producer->getRawProducer()->abortTransaction($timeoutMs);
                } catch (Throwable $e) {
                    self::logException($e);
                }

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

            try {
                $producer->getRawProducer()->abortTransaction($timeoutMs);
            } catch (Throwable $e) {
                self::logException($e);
            }

            if ($onFailed != null) {
                $onFailed($producer->getName(), $message, $key);
            }
            if ($exception) {
                throw $exception;
            }
        }

    }

    public static function doSendMessageInTransaction(
        ProducerConfiguration $producer,
        mixed $message,
        mixed $key,
        int $timeoutMs
    ): void {

        $topic = $producer->getTopicObject();

        $producer->getRawProducer()->initTransactions($timeoutMs);
        $producer->getRawProducer()->beginTransaction();

        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
        $producer->getRawProducer()->poll(0);

        $producer->getRawProducer()->commitTransaction($timeoutMs);
    }

}