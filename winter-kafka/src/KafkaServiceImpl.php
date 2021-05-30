<?php

namespace dev\winterframework\kafka;

use dev\winterframework\core\context\WinterServer;
use dev\winterframework\kafka\consumer\Consumer;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\consumer\ConsumerConfigurations;
use dev\winterframework\kafka\consumer\ConsumerRecord;
use dev\winterframework\kafka\consumer\ConsumerRecords;
use dev\winterframework\kafka\exception\KafkaException;
use dev\winterframework\kafka\producer\ProducerConfiguration;
use dev\winterframework\kafka\producer\ProducerConfigurations;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\util\log\Wlf4p;
use Swoole\Process;
use Throwable;

class KafkaServiceImpl implements KafkaService {
    use Wlf4p;

    protected ProducerConfigurations $producers;
    protected ConsumerConfigurations $consumers;

    #[Autowired]
    private WinterServer $wServer;

    public function __construct() {
        $this->producers = new ProducerConfigurations();
        $this->consumers = new ConsumerConfigurations();
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
        }

        try {
            $topic = $producer->getTopicObject();

            $producer->getRawProducer()->initTransactions(10000);
            $producer->getRawProducer()->beginTransaction();

            $topic->produce(RD_KAFKA_PARTITION_UA, 0, $message, $key);
            $producer->getRawProducer()->poll(0);

            $producer->getRawProducer()->commitTransaction(10000);

        } catch (Throwable $e) {
            throw new KafkaException('Could not produce a message to Kafka producer '
                . $producerOrName, 0, $e);
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
        });

    }

    public function beginConsume(): void {
        foreach ($this->consumers as $consumer) {

            /** @var ConsumerConfiguration $consumer */
            for ($i = 0; $i < $consumer->getWorkerNum(); $i++) {

                $this->wServer->getServer()->addProcess(

                    new Process(function ($process) use ($consumer, $i) {

                        self::logInfo("Starting Kafka consumer '" . $consumer->getName()
                            . "' worker-" . ($i + 1) . ',  pid: ' . $process->pid);

                        $consumer->getRawConsumer()->subscribe($consumer->getTopics());
                        $workerClass = $consumer->getWorkerClass();
                        /** @var Consumer $worker */
                        $worker = new $workerClass($consumer);

                        while (true) {
                            $message = $consumer->getRawConsumer()->consume(120 * 1000);

                            switch ($message->err) {

                                case RD_KAFKA_RESP_ERR_NO_ERROR:
                                    $record = ConsumerRecord::fromMessage(
                                        $message,
                                        $consumer->getName()
                                    );
                                    $worker->consume(ConsumerRecords::ofValues($record));
                                    break;

                                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                                    self::logDebug('No more kafka messages; will wait for more');
                                    break;

                                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                                    self::logDebug('Kafka consumer TCP connection Timed out, re-listening!');
                                    break;

                                default:
                                    throw new KafkaException($message->errstr(), $message->err);
                            }
                        }
                    })
                );
            }
        }
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