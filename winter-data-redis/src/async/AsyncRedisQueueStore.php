<?php
/** @noinspection PhpUndefinedMethodInspection */
declare(strict_types=1);

namespace dev\winterframework\data\redis\async;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\data\redis\util\RedisUtil;
use dev\winterframework\util\async\AsyncQueueRecord;
use dev\winterframework\util\async\AsyncQueueStore;
use dev\winterframework\util\log\Wlf4p;

class AsyncRedisQueueStore implements AsyncQueueStore {
    use Wlf4p;

    const PREFIX = 'winter-async-queue-';
    private PhpRedisAbstractTemplate $redis;
    private string $queueName;
    private string $counterName;

    public function __construct(
        private ApplicationContext $ctx,
        private int $workerId,
        private int $capacity,
        private int $argSize
    ) {
        $appId = $ctx->getId();

        $redisBean = $this->ctx->getPropertyStr('winter.task.async.queueStorage.redisBean', '');

        $this->redis = RedisUtil::getRedisBean($this->ctx, $redisBean);

        $this->queueName = $appId . '-' . self::PREFIX . 'work-' . $this->workerId;
        $this->counterName = $appId . '-' . self::PREFIX . 'counter-' . $this->workerId;
    }

    public function enqueue(AsyncQueueRecord $record): int {
        if ($record->getId() == 0) {
            $record->setId($this->redis->incr($this->counterName));
        }

        $this->redis->rPush($this->queueName, json_encode($record->toArray()));
        return $record->getId();
    }

    public function dequeue(): ?AsyncQueueRecord {
        $val = $this->redis->lPop_xwait($this->queueName);

        if (!$val) {
            return null;
        }
        return AsyncQueueRecord::fromArray(0, json_decode($val, true));
    }

    public function size(): int {
        return $this->redis->lLen($this->queueName);
    }

    public function getAll(int $limit = PHP_INT_MAX): array {
        $size = $this->redis->lLen_xwait($this->queueName);

        if ($limit > $size) {
            $limit = $size;
        }
        $values = $this->redis->lRange_xwait($this->queueName, 0, $limit);
        $records = [];
        foreach ($values as $value) {
            $records[] = AsyncQueueRecord::fromArray(0, json_decode($value, true));
        }

        return $records;
    }

    public function deleteAll(): void {
        $this->redis->del($this->queueName);
    }

}