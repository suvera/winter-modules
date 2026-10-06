<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\data\redis\consumer\Consumer;
use dev\winterframework\data\redis\consumer\ConsumerConfiguration;
use dev\winterframework\data\redis\consumer\ConsumerRecord;
use dev\winterframework\data\redis\consumer\ConsumerRecords;
use dev\winterframework\io\process\ProcessType;
use dev\winterframework\io\process\ServerWorkerProcess;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\util\ExceptionUtils;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

/**
 * Reliable queue worker over Redis Streams (Redisson PRO Reliable Queue style).
 *
 * At-least-once delivery: entries are XACKed only after the worker consumes
 * them successfully. Unacked entries stay in the group PEL and are reclaimed
 * via XCLAIM once idle past claimIdleMs; entries redelivered past
 * maxDeliveries (or failing permanently) go to the dead-letter stream.
 */
class RedisQueueWorkerProcess extends ServerWorkerProcess {
    use Wlf4p;

    protected ConsumerConfiguration $consumer;
    protected string|int $workerId;
    protected float $lastReclaimAt = 0;

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
        return 'redis-queue-consumer-' . $this->consumer->getName() . '-' . $this->workerId;
    }

    protected function run(): void {
        $queueName = $this->consumer->getName();
        $consumerName = $this->consumer->getGroup() . '-' . $this->workerId;

        $this->awaitConsumerGroup();

        self::logInfo("Redis queue consumer starting. '" . $queueName
            . "' redis-queue-worker-" . $this->workerId . ',  pid: ' . $this->process->pid
            . ',  mypid: ' . getmypid()
            . ',  workerClass: ' . $this->consumer->getWorkerClass()
            . ',  stream: ' . $this->consumer->getStream()
            . ',  group: ' . $this->consumer->getGroup()
            . ',  consumer: ' . $consumerName);

        $workerClass = $this->consumer->getWorkerClass();
        /** @var Consumer $worker */
        $worker = ReflectionUtil::createAutoWiredObject(
            $this->appCtx,
            new RefKlass($workerClass),
            $this->appCtx,
            $this->consumer
        );

        $pollIntervalMs = intval($this->consumer->getConfigVal('pollIntervalMs', 0));

        while (true) {
            try {
                $this->reclaimStale($consumerName, $worker);
                $records = $this->readNew($consumerName);

                if ($records->count() > 0) {
                    self::logDebug('Received ' . $records->count()
                        . ' messages from stream ' . $queueName);
                    $this->consumeBatch($records, $worker);
                }
            } catch (Throwable $e) {
                self::logException($e);
                usleep(500 * 1000);
            }

            if ($pollIntervalMs > 0) {
                usleep($pollIntervalMs * 1000);
            }
        }
    }

    /**
     * Create the consumer group, retrying while Redis is unreachable. A
     * worker process that throws or returns shuts the whole server down
     * (winter-boot 2.1), so a Redis outage at startup must not escape.
     */
    protected function awaitConsumerGroup(): void {
        $waitMs = 0;
        while (true) {
            try {
                $this->consumer->ensureConsumerGroup();
                return;
            } catch (Throwable $e) {
                $waitMs = min($waitMs + 1000, 30000);
                self::logException($e, 'Could not create consumer group ' . $this->consumer->getGroup()
                    . ' on stream ' . $this->consumer->getStream() . ', retrying in ' . $waitMs . 'ms: ');
                usleep($waitMs * 1000);
            }
        }
    }

    protected function readNew(string $consumerName): ConsumerRecords {
        $stream = $this->consumer->getStream();
        $group = $this->consumer->getGroup();

        try {
            $result = $this->consumer->getRedis()->xreadgroup(
                $group,
                $consumerName,
                [$stream => '>'],
                $this->consumer->getBatchSize(),
                $this->consumer->getBlockMs()
            );
        } catch (Throwable $e) {
            self::logException($e);
            return new ConsumerRecords();
        }

        return ConsumerRecords::fromStreamResult($stream, $result, $group);
    }

    /**
     * Reclaim PEL entries idle past claimIdleMs (e.g. from crashed workers).
     * Throttled to reclaimIntervalMs so XPENDING does not run every iteration.
     */
    protected function reclaimStale(string $consumerName, Consumer $worker): void {
        $reclaimIntervalMs = $this->consumer->getReclaimIntervalMs();
        $now = microtime(true) * 1000;
        if ($now - $this->lastReclaimAt < $reclaimIntervalMs) {
            return;
        }
        $this->lastReclaimAt = $now;

        $stream = $this->consumer->getStream();
        $group = $this->consumer->getGroup();
        $claimIdleMs = $this->consumer->getClaimIdleMs();

        try {
            $pending = $this->consumer->getRedis()->xpending(
                $stream,
                $group,
                '-',
                '+',
                $this->consumer->getBatchSize()
            );
        } catch (Throwable $e) {
            self::logException($e);
            return;
        }

        if (!is_array($pending) || !$pending) {
            return;
        }

        $staleIds = [];
        $deliveries = [];
        foreach ($pending as $entry) {
            $parsed = $this->parsePendingEntry($entry);
            if (!$parsed) {
                continue;
            }
            [$id, $idleMs, $deliveryCount] = $parsed;
            if ($idleMs >= $claimIdleMs) {
                $staleIds[] = $id;
                $deliveries[$id] = $deliveryCount;
            }
        }

        if (!$staleIds) {
            return;
        }

        try {
            $claimed = $this->consumer->getRedis()->xclaim(
                $stream,
                $group,
                $consumerName,
                $claimIdleMs,
                $staleIds
            );
        } catch (Throwable $e) {
            self::logException($e);
            return;
        }

        if (!is_array($claimed) || !$claimed) {
            return;
        }

        self::logInfo('Reclaimed ' . count($claimed) . ' stale message(s) from stream '
            . $this->consumer->getStream());

        $records = new ConsumerRecords();
        foreach ($claimed as $id => $fields) {
            if (!is_array($fields)) {
                continue;
            }
            $records[] = ConsumerRecord::fromStreamEntry(
                $stream,
                strval($id),
                $fields,
                $group,
                intval($deliveries[strval($id)] ?? 1) + 1
            );
        }

        if ($records->count() > 0) {
            $this->consumeBatch($records, $worker);
        }
    }

    /**
     * Parse one XPENDING range entry: [id, consumer, idleMs, deliveryCount].
     * Tolerates both numeric and associative shapes.
     */
    protected function parsePendingEntry(mixed $entry): ?array {
        if (!is_array($entry)) {
            return null;
        }

        $id = $entry[0] ?? $entry['id'] ?? null;
        $idleMs = $entry[2] ?? $entry['idle'] ?? null;
        $deliveryCount = $entry[3] ?? $entry['deliveryCount'] ?? $entry['delivered'] ?? 1;

        if ($id === null || $idleMs === null) {
            return null;
        }

        return [strval($id), intval($idleMs), intval($deliveryCount)];
    }

    protected function consumeBatch(ConsumerRecords $records, Consumer $worker): void {
        $maxDeliveries = $this->consumer->getMaxDeliveries();

        $ready = new ConsumerRecords();
        foreach ($records as $record) {
            /** @var ConsumerRecord $record */
            if ($record->getDeliveryCount() > $maxDeliveries) {
                $this->moveToDeadLetter(
                    $record,
                    'max-deliveries-exceeded deliveries=' . $record->getDeliveryCount()
                );
            } else {
                $ready[] = $record;
            }
        }

        // consumeOne() acks each record on success and routes failures to
        // the dead letter (or leaves them pending for redelivery).
        foreach ($ready as $record) {
            /** @var ConsumerRecord $record */
            $this->consumeOne($record, $worker);
        }
    }

    protected function consumeOne(ConsumerRecord $record, Consumer $worker): void {
        $single = new ConsumerRecords();
        $single[] = $record;

        $trials = $this->consumer->getRetries();
        $reTriableExceptions = $this->consumer->getTransientExceptions();
        $retryWaitMs = $this->consumer->getRetryWaitMs();

        while ($trials > 0) {
            $trials--;
            try {
                $worker->consume($single);
                $this->ack($single);
                return;
            } catch (Throwable $e) {
                self::logException($e);
                if (ExceptionUtils::inExceptions($e, $reTriableExceptions)) {
                    if ($trials <= 0) {
                        // Leave pending: redelivered after claimIdleMs.
                        return;
                    }
                    usleep($trials * $retryWaitMs * 1000);
                    continue;
                }

                $this->failPermanent($single, $e);
                return;
            }
        }
    }

    protected function ack(ConsumerRecords $records): void {
        $ids = $records->getIds();
        if (!$ids) {
            return;
        }

        try {
            $this->consumer->getRedis()->xack(
                $this->consumer->getStream(),
                $this->consumer->getGroup(),
                $ids
            );
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    protected function failPermanent(ConsumerRecords $records, Throwable $e): void {
        foreach ($records as $record) {
            /** @var ConsumerRecord $record */
            $this->moveToDeadLetter($record, get_class($e) . ': ' . $e->getMessage());
        }
    }

    protected function moveToDeadLetter(ConsumerRecord $record, string $reason): void {
        $dlq = $this->consumer->getDeadLetterStream();

        try {
            if ($dlq) {
                $this->consumer->getRedis()->xadd($dlq, '*', array_merge(
                    $record->getFields(),
                    [
                        'failedStream' => $record->getStream(),
                        'failedId' => $record->getId(),
                        'failedReason' => substr($reason, 0, 1024),
                        'failedAt' => strval(intval(microtime(true) * 1000)),
                    ]
                ));
            } else {
                self::logError('Dropping poison message ' . $record->getId()
                    . ' from stream ' . $record->getStream()
                    . ' (no deadLetterStream configured): ' . $reason);
            }

            $single = new ConsumerRecords();
            $single[] = $record;
            $this->ack($single);
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

}
