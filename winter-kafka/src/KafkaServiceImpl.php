<?php
declare(strict_types=1);

namespace dev\winterframework\kafka;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\consumer\ConsumerConfigurations;
use dev\winterframework\kafka\exception\KafkaException;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\kafka\producer\ProducerConfigurations;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\util\log\Wlf4p;

class KafkaServiceImpl implements KafkaService {
    use Wlf4p;

    protected ProducerConfigurations $producers;
    protected ConsumerConfigurations $consumers;

    #[Autowired]
    private WinterServer $wServer;

    #[Autowired]
    private ApplicationContext $appCtx;

    private bool $consumerStarted = false;

    public function __construct() {
        $this->producers = new ProducerConfigurations();
        $this->consumers = new ConsumerConfigurations();
    }


    /**
     * @throws
     */
    public function produce(
        string|ProducerConfiguration $producerOrName,
        mixed $message,
        mixed $key
    ): bool {
        if (!($producerOrName instanceof ProducerConfiguration)) {
            if (!isset($this->producers[$producerOrName])) {
                throw new KafkaException('Could not find Kafka producer ' . $producerOrName);
            }
            $producer = $this->producers[$producerOrName];
        } else {
            $producer = $producerOrName;
        }

        if ($producer->isTransactionEnabled()) {
            KafkaUtil::sendMessageInTransaction($producer, $message, $key);
        } else {
            KafkaUtil::sendMessage($producer, $message, $key);
        }

        return true;
    }

    /**
     * @throws
     */
    public function produceAsync(
        string|ProducerConfiguration $producerOrName,
        mixed $message,
        mixed $key,
        callable $onSuccess = null,
        callable $onFailed = null
    ): void {
        if (!($producerOrName instanceof ProducerConfiguration)) {
            if (!isset($this->producers[$producerOrName])) {
                throw new KafkaException('Could not find Kafka producer ' . $producerOrName);
            }
            $producer = $this->producers[$producerOrName];
        } else {
            $producer = $producerOrName;
        }

        go(function () use ($producer, $message, $key, $onSuccess, $onFailed) {

            if ($producer->isTransactionEnabled()) {
                KafkaUtil::sendMessageInTransaction($producer, $message, $key, $onSuccess, $onFailed);
            } else {
                KafkaUtil::sendMessage($producer, $message, $key, $onSuccess, $onFailed);
            }

        });
    }

    protected function startConsumer(ConsumerConfiguration $consumer, int $i): void {
        $ps = new KafkaWorkerProcess($this->wServer, $this->appCtx, $consumer, $i + 1);
        $this->wServer->addProcess($ps);
    }

    public function beginConsume(): void {

        if ($this->consumerStarted) {
            return;
        }

        foreach ($this->consumers as $consumer) {
            /** @var ConsumerConfiguration $consumer */

            for ($i = 0; $i < $consumer->getWorkerNum(); $i++) {
                $this->startConsumer($consumer, $i);
            }
        }

        $this->consumerStarted = true;
    }

    public function getProducers(): ProducerConfigurations {
        return $this->producers;
    }

    public function getConsumers(): ConsumerConfigurations {
        return $this->consumers;
    }

    public function addConsumer(ConsumerConfiguration $config): void {
        $this->consumers[] = $config;
    }

    public function addProducer(ProducerConfiguration $config): void {
        $this->producers[] = $config;
    }


}