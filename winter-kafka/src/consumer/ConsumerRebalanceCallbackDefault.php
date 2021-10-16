<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\kafka\exception\KafkaRebalanceException;
use dev\winterframework\kafka\KafkaUtil;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\KafkaConsumer;

class ConsumerRebalanceCallbackDefault implements ConsumerRebalanceCallback {
    use Wlf4p;

    public function __construct(
        protected ConsumerConfiguration $config,
        protected ApplicationContext $ctx
    ) {
    }

    /**
     * @throws
     */
    public function __invoke(KafkaConsumer $kafka, mixed $err, array $partitions = null): void {
        switch ($err) {
            case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                self::logInfo("Assigning Kafka partitions: " . KafkaUtil::toPartitionsString($partitions));
                $kafka->assign($partitions);
                break;

            case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                self::logInfo("Revoking Kafka partitions: " . KafkaUtil::toPartitionsString($partitions));
                $kafka->assign(null);
                break;

            default:
                $kafka->assign(null);
                throw new KafkaRebalanceException($err);
        }
    }
}