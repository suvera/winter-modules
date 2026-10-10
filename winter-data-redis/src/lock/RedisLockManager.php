<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\lock;

use dev\winterframework\util\concurrent\StoreLockManager;

/**
 * Distributed #[Lockable] locking in Redis (see RedisLockStore).
 *
 *   #[Bean('redisLockManager')]
 *   public function redisLockManager(PhpRedisTemplate $redis): LockManager {
 *       return new RedisLockManager($redis);
 *   }
 *
 *   #[Lockable(name: 'order-#{id}', ttlSeconds: 30, lockManager: 'redisLockManager')]
 */
class RedisLockManager extends StoreLockManager {

    /**
     * @param int $pollMs pause between attempts while waiting (waitMilliSecs)
     */
    public function __construct(
        object $redis,
        string $prefix = RedisLockStore::DEFAULT_PREFIX,
        int $pollMs = 50,
    ) {
        parent::__construct(new RedisLockStore($redis, $prefix), $pollMs);
    }
}
