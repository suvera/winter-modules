<?php
declare(strict_types=1);

namespace dev\winterframework\kafka;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\ProcessType;
use dev\winterframework\io\process\ServerWorkerProcess;
use dev\winterframework\kafka\consumer\Consumer;
use dev\winterframework\kafka\consumer\ConsumerConfiguration;
use dev\winterframework\kafka\consumer\ConsumerRecord;
use dev\winterframework\kafka\consumer\ConsumerRecords;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\util\ExceptionUtils;
use dev\winterframework\util\log\Wlf4p;
use RdKafka\KafkaConsumerTopic;
use RuntimeException;
use Throwable;

class KafkaWorkerProcess extends ServerWorkerProcess {
    use Wlf4p;

    protected ConsumerConfiguration $consumer;
    protected string|int $workerId;
    protected bool $validated = false;

    public function __construct(
        WinterServer $wServer,
        ApplicationContext $ctx,
        ConsumerConfiguration $consumer,
        string|int $workerId
    ) {
        parent::__construct($wServer, $ctx);
        $this->consumer = $consumer;
        $this->workerId = $workerId;
    }

    public function getProcessType(): int {
        return ProcessType::OTHER;
    }

    public function getProcessId(): string {
        return 'kafka-consumer-' . $this->workerId;
    }

    protected function run(): void {
        $topics = $this->consumer->getTopics();

        if (!$topics) {
            self::logInfo('No topics found for consumer ' . $this->consumer->getName());
            return;
        }

        //$this->validate();

        $this->consumer->getRawConsumer()->subscribe($topics);

        self::logInfo("Kafka consumer subscribed. '" . $this->consumer->getName()
            . "' kafka-worker-" . $this->workerId . ',  pid: ' . $this->process->pid . ',  mypid: ' . getmypid()
            . ', topics: ' . json_encode($this->consumer->getTopics()));

        $workerClass = $this->consumer->getWorkerClass();
        /** @var Consumer $worker */
        $worker = ReflectionUtil::createAutoWiredObject(
            $this->appCtx,
            new RefKlass($workerClass),
            $this->appCtx,
            $this->consumer
        );

        while (true) {
            $firstTime = true;
            $message = $this->consumer->getRawConsumer()->consume(120 * 1000);

            switch ($message->err) {

                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $record = ConsumerRecord::fromMessage(
                        $message,
                        $this->consumer->getName()
                    );
                    $records = ConsumerRecords::ofValues($record);
                    $this->consumerRecords($records, $worker);
                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    self::logDebug('No more kafka messages; will wait for more');
                    break;

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    self::logDebug('Kafka consumer TCP connection Timed out, re-listening!');
                    break;

                case RD_KAFKA_RESP_ERR_UNKNOWN_TOPIC_OR_PART:
                case RD_KAFKA_RESP_ERR__UNKNOWN_TOPIC:
                case RD_KAFKA_RESP_ERR_TOPIC_EXCEPTION:
                    $err = 'Kafka error ' . $message->err . ': ' . $message->errstr();
                    $err .= ', Consumer: ' . $this->consumer->getName()
                        . ', Topics: ' . json_encode($this->consumer->getTopics());
                    self::logError($err);
                    if ($firstTime) {
                        $this->wServer->shutdown($err);
                    }
                    break;

                default:
                    self::logError('Kafka error ' . $message->err . ': ' . $message->errstr());
                    break;
            }

            /** @noinspection PhpUnusedLocalVariableInspection */
            $firstTime = false;
            //\Co\System::sleep(0.2); //200000);
        }
    }

    protected function validate(): void {
        if ($this->validated) {
            return;
        }

        try {
            foreach ($this->consumer->getTopics() as $topic) {
                /** @var KafkaConsumerTopic $topicObj */
                /** @noinspection PhpUndefinedMethodInspection */
                $topicObj = $this->consumer->getRawConsumer()->newTopic($topic);
                $metadata = $this->consumer->getRawConsumer()->getMetadata(false, $topicObj, 10000);

                $topics = $metadata->getTopics();

                unset($topicObj);
                unset($metadata);
                $this->consumer->unsetRawConsumer();

                if ($topics->count() != 1) {
                    throw new RuntimeException('Kafka Topic "' . $topic . '" does not exist ');
                }

                foreach ($topics as $t) {
                    if ($t->getErr()) {
                        throw new RuntimeException('Kafka Topic "' . $topic . '" does not exist. "'
                            . rd_kafka_err2str($t->getErr()) . '" ');
                    }
                }
            }

            $this->validated = true;
        } catch (Throwable $e) {
            self::logException($e);
            $this->wServer->shutdown(null, $e);
        }
    }

    protected function consumerRecords(ConsumerRecords $records, Consumer $worker): void {
        $trials = $this->consumer->getRetries();
        $reTriableExceptions = $this->consumer->getTransientExceptions();
        $retryWaitMs = $this->consumer->getRetryWaitMs();

        while ($trials > 0) {
            $trials--;
            try {
                $worker->consume($records);
            } catch (Throwable $e) {
                self::logException($e);
                if (ExceptionUtils::inExceptions($e, $reTriableExceptions)) {
                    usleep($trials * $retryWaitMs * 1000);
                    continue;
                }
            }
        }
    }

}