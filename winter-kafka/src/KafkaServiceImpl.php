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
use RuntimeException;
use Throwable;

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

    protected static function sendMessage(
        ProducerConfiguration $producer,
        string $producerOrName,
        mixed $message,
        mixed $key,
        ?callable $onSuccess = null,
        ?callable $onFailed = null
    ) {
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
                throw new RuntimeException('Was unable to flush to kafka, messages might be lost!');
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

    protected static function sendMessageInTransaction(
        ProducerConfiguration $producer,
        string $producerOrName,
        mixed $message,
        mixed $key,
        ?callable $onSuccess = null,
        ?callable $onFailed = null
    ) {

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
            $producerOrName = $producer->getName();
        }

        if ($producer->isTransactionEnabled()) {
            self::sendMessageInTransaction($producer, $producerOrName, $message, $key);
        } else {
            self::sendMessage($producer, $producerOrName, $message, $key);
        }

        return true;
    }

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

        go(function () use ($producer, $producerOrName, $message, $key, $onSuccess, $onFailed) {

            if ($producer->isTransactionEnabled()) {
                self::sendMessageInTransaction($producer, $producerOrName, $message, $key, $onSuccess, $onFailed);
            } else {
                self::sendMessage($producer, $producerOrName, $message, $key, $onSuccess, $onFailed);
            }

        });
    }

    protected function startConsumer(ConsumerConfiguration $consumer, int $i): void {
        //$consumer->getRawConsumer()->subscribe($consumer->getTopics());
        //self::logInfo('Consumer Subscribed ... ');

        $ps = new KafkaWorkerProcess($this->wServer, $this->appCtx, $consumer, $i + 1);
        $this->wServer->getServer()->addProcess($ps);
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