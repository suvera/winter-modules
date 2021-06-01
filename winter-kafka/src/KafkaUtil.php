<?php
declare(strict_types=1);


namespace dev\winterframework\kafka;


use dev\winterframework\util\log\Wlf4p;
use RdKafka\TopicPartition;

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
}