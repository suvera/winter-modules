<?php
declare(strict_types=1);


namespace dev\winterframework\kafka;


use dev\winterframework\kafka\exception\KafkaException;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\util\log\Wlf4p;
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
        $producerOrName = $producer->getName();
        try {
            $topic = $producer->getTopicObject();

            self::logInfo("messaged produced to " . $topic->getName());
            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
            $producer->getRawProducer()->poll(0);

            $result = null;
            for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
                $result = $producer->getRawProducer()->flush(10000);
                if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                    break;
                }
            }

            if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
                if ($onFailed != null) {
                    $onFailed($producerOrName, $message, $key);
                }
                throw new KafkaException('Was unable to flush to kafka, messages might be lost!');
            } else {

                if ($onSuccess != null) {
                    $onSuccess($producer, $message, $key);
                }
            }

        } catch (Throwable $e) {
            self::logException($e);
            $producer->getRawProducer()->purge(RD_KAFKA_PURGE_F_QUEUE);

            if ($onFailed != null) {
                $onFailed($producerOrName, $message, $key);
            }

            throw new KafkaException($e->getMessage(), 0, $e);
        }
    }

    public static function sendMessageInTransaction(
        ProducerConfiguration $producer,
        mixed $message,
        mixed $key,
        ?callable $onSuccess = null,
        ?callable $onFailed = null
    ): void {

        $producerOrName = $producer->getName();
        try {
            $topic = $producer->getTopicObject();

            $producer->getRawProducer()->initTransactions(10000);
            $producer->getRawProducer()->beginTransaction();

            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
            $producer->getRawProducer()->poll(0);

            $producer->getRawProducer()->commitTransaction(10000);

            if ($onSuccess != null) {
                $onSuccess($producer, $message, $key);
            }

        } catch (Throwable $e) {
            self::logException($e);

            if ($onFailed != null) {
                $onFailed($producerOrName, $message, $key);
            }
        }

    }

}