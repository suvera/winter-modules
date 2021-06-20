<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\data\redis\RedisModule;
use dev\winterframework\data\redis\util\RedisUtil;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\io\stream\InputStream;
use dev\winterframework\io\stream\StringInputStream;

class TaskIOStorageRedis implements TaskIOStorageHandler {
    protected int $ttl;
    protected PhpRedisAbstractTemplate $redis;
    const PREFIX = 'winter-dtce-io-';

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $taskDef
    ) {
        if (!$this->ctx->hasModule(RedisModule::class)) {
            throw new DtceException('Redis Module is needed to use Redis Queue');
        }

        $redisBean = $this->taskDef['storage.redis.bean'] ?? '';

        $this->ttl = $this->taskDef['storage.redis.ttl'] ?? 14400;

        $this->redis = RedisUtil::getRedisBean($this->ctx, $redisBean);
    }

    public function getInputStream(int|string $dataId): InputStream {
        $value = $this->redis->get(self::PREFIX . $dataId);

        if (is_null($value) || $value === false) {
            throw new DtceException('Could not find data for dataId ' . $dataId . ' in task store');
        }

        return new StringInputStream($value);
    }

    public function put(int|string $dataId, string $data): void {
        $this->redis->set(self::PREFIX . $dataId, $data, $this->ttl);
    }

}