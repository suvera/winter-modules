<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\KafkaConsumer;

class ConsumerErrorCallbackDefault implements ConsumerErrorCallback {
    use Wlf4p;

    public function __construct(
        protected ConsumerConfiguration $config,
        protected ApplicationContext $ctx
    ) {
    }

    public function __invoke(KafkaConsumer $kafka, int $err, string $reason): void {
        self::logCritical(sprintf("Kafka Critical error: %s (reason: %s), Consumer: %s\n",
            rd_kafka_err2str($err), $reason, $this->config->getName()
        ));
    }


}