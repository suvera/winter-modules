<?php
/** @noinspection PhpUndefinedMethodInspection */
declare(strict_types=1);

namespace dev\winterframework\data\redis\util;

use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\type\Queue;

class RedisQueue implements Queue {

    public function __construct(
        protected PhpRedisAbstractTemplate $redis,
        protected string $queueName,
        protected int $capacity = 0
    ) {
    }

    public function add(mixed $item, int $timeoutMs = 0): bool {
        $this->redis->rPush($this->queueName, $item);
        return true;
    }

    public function poll(int $timeoutMs = 0): mixed {
        return $this->redis->lPop_xwait($this->queueName);
    }

    public function isUnbounded(): bool {
        return true;
    }

    public function size(): int {
        return $this->redis->lLen($this->queueName);
    }

    public function isCountable(): bool {
        return true;
    }

}