<?php
declare(strict_types=1);

namespace dev\winterframework\kafka;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\KafkaConsumer;

class KafkaLogCallbackDefault implements KafkaLogCallback {
    use Wlf4p;

    public function __construct(
        protected ProducerConfiguration|ConsumerConfiguration $config,
        protected ApplicationContext $ctx
    ) {
    }

    public function __invoke(KafkaConsumer $kafka, int $level, string $facility, string $message): void {
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