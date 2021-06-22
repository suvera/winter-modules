<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\data\redis\RedisModule;
use dev\winterframework\data\redis\util\RedisQueue;
use dev\winterframework\data\redis\util\RedisUtil;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\type\Queue;
use dev\winterframework\type\TypeAssert;

class TaskQueueRedis extends TaskQueueAbstract {
    const PREFIX = 'winter-dtce-queue-';

    protected function buildTaskQueue(): Queue {
        if (!$this->ctx->hasModule(RedisModule::class)) {
            throw new DtceException('Redis Module is needed to use Redis Queue');
        }

        $redisBean = $this->taskDef['queue.redis.bean'] ?? '';
        if (!isset($this->taskDef['queue.redis.key']) || !$this->taskDef['queue.redis.key']) {
            $this->taskDef['queue.redis.key'] = self::PREFIX . hash('sha256', $this->taskName());
        }

        $redis = RedisUtil::getRedisBean($this->ctx, $redisBean);
        TypeAssert::notEmpty('redis object', $redis, 'Could not fetch redis object');

        return new RedisQueue($redis, $this->taskDef['queue.redis.key'], $this->taskDef['queue.capacity']);
    }

}