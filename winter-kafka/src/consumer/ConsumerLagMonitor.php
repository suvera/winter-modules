<?php
declare(strict_types=1);

namespace dev\winterframework\kafka\consumer;

use dev\winterframework\util\log\Wlf4p;
use RdKafka\KafkaConsumer;

class ConsumerLagMonitor implements ConsumerStatistics {
    use Wlf4p;

    public function __construct(
        protected ConsumerConfiguration $config
    ) {
    }

    public function __invoke(KafkaConsumer $kafka, string $json, int $json_len): void {
        $stats = json_decode($json, true);
        if ($stats === false) {
            self::logError('Kafka consumer stats callback received invalid JSON');
            return;
        }

        foreach ($stats['topics'] as $topicName => $topicStats) {
            foreach ($topicStats['partitions'] as $partId => $partStats) {
                if (!isset($partStats['consumer_lag']) || $partStats['consumer_lag'] < 0) {
                    continue;
                }
                self::logInfo('Consumer:' . $this->config->getName()
                    . ', topic:' . $topicName
                    . ', partition:' . $partId
                    . ', lag:' . $partStats['consumer_lag']
                );
            }
        }
    }

}