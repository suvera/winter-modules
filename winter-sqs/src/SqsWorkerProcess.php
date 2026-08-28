<?php
declare(strict_types=1);

namespace dev\winterframework\sqs;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\io\process\ProcessType;
use dev\winterframework\io\process\ServerWorkerProcess;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\sqs\consumer\Consumer;
use dev\winterframework\sqs\consumer\ConsumerConfiguration;
use dev\winterframework\sqs\consumer\ConsumerRecord;
use dev\winterframework\sqs\consumer\ConsumerRecords;
use dev\winterframework\util\ExceptionUtils;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class SqsWorkerProcess extends ServerWorkerProcess {
    use Wlf4p;

    protected ConsumerConfiguration $consumer;
    protected string|int $workerId;

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
        return 'sqs-consumer-' . $this->workerId;
    }

    protected function run(): void {
        $queueName = $this->consumer->getName();

        self::logInfo("SQS consumer starting. '" . $queueName
            . "' sqs-worker-" . $this->workerId . ',  pid: ' . $this->process->pid
            . ',  mypid: ' . getmypid()
            . ',  workerClass: ' . $this->consumer->getWorkerClass()
            . ',  queueName: ' . $this->consumer->getQueueName()
            . ',  connection: ' . $this->consumer->getConnectionName());

        $workerClass = $this->consumer->getWorkerClass();
        self::logInfo('Creating worker instance of class: ' . $workerClass);
        /** @var Consumer $worker */
        $worker = ReflectionUtil::createAutoWiredObject(
            $this->appCtx,
            new RefKlass($workerClass),
            $this->appCtx,
            $this->consumer
        );
        self::logInfo('Worker instance created: ' . get_class($worker));

        $pollIntervalMs = intval($this->consumer->getConfigVal('pollIntervalMs', 0));
        self::logInfo('Entering SQS poll loop, pollIntervalMs=' . $pollIntervalMs);

        while (true) {
            $records = $this->receiveRecords();
            self::logDebug('Received ' . $records->count() . ' messages from queue ' . $queueName);

            if ($records->count() > 0) {
                $this->consumerRecords($records, $worker);
                $this->deleteRecords($records);
            }

            if ($pollIntervalMs > 0) {
                usleep($pollIntervalMs * 1000);
            }
        }
    }

    protected function receiveRecords(): ConsumerRecords {
        $records = new ConsumerRecords();

        try {
            $client = $this->consumer->getRawClient();
            $queueUrl = $this->consumer->resolveQueueUrl();

            $result = $client->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => intval(
                    $this->consumer->getConfigVal('maxNumberOfMessages', 10)
                ),
                'WaitTimeSeconds' => intval(
                    $this->consumer->getConfigVal('waitTimeSeconds', 20)
                ),
                'VisibilityTimeout' => intval(
                    $this->consumer->getConfigVal('visibilityTimeout', 30)
                ),
            ]);

            $messages = $result['Messages'] ?? [];
            foreach ($messages as $message) {
                $records[] = ConsumerRecord::fromResult($message, $this->consumer->getName());
            }
        } catch (Throwable $e) {
            self::logException($e);
        }

        return $records;
    }

    protected function deleteRecords(ConsumerRecords $records): void {
        $handles = [];
        foreach ($records as $record) {
            /** @var ConsumerRecord $record */
            $handles[] = [
                'Id' => $record->getMessageId(),
                'ReceiptHandle' => $record->getReceiptHandle(),
            ];
        }

        if (!$handles) {
            return;
        }

        try {
            $this->consumer->getRawClient()->deleteMessageBatch([
                'QueueUrl' => $this->consumer->resolveQueueUrl(),
                'Entries' => $handles,
            ]);
        } catch (Throwable $e) {
            self::logException($e);
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

                return;
            } catch (Throwable $e) {
                self::logException($e);
                if (ExceptionUtils::inExceptions($e, $reTriableExceptions)) {
                    usleep($trials * $retryWaitMs * 1000);
                    continue;
                }

                return;
            }
        }
    }

}