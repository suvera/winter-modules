<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\lock;

use dev\winterframework\util\concurrent\LockStore;

/**
 * Lock leases in Redis: one key per held lock, holding the owner's random
 * token, with a PEXPIRE TTL when one is set. Expiry uses the Redis
 * server's clock, so pod clocks don't matter.
 *
 * Every operation is one Lua script, so checking the owner and changing
 * the key happen atomically, and values and key prefixes are encoded the
 * same way for acquire, release and refresh. Use a single Redis, Sentinel
 * or Cluster template (Cluster routes the one key per call); not a client-
 * side sharded array, where the scripts may land on different nodes.
 */
class RedisLockStore implements LockStore {

    public const DEFAULT_PREFIX = 'winter:lock:';

    private const ACQUIRE = <<<'LUA'
if tonumber(ARGV[2]) > 0 then
    if redis.call('SET', KEYS[1], ARGV[1], 'NX', 'PX', ARGV[2]) then return 1 end
else
    if redis.call('SET', KEYS[1], ARGV[1], 'NX') then return 1 end
end
return 0
LUA;

    private const RELEASE = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    return redis.call('DEL', KEYS[1])
end
return 0
LUA;

    private const REFRESH = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
    if tonumber(ARGV[2]) > 0 then
        return redis.call('PEXPIRE', KEYS[1], ARGV[2])
    end
    redis.call('PERSIST', KEYS[1])
    return 1
end
return 0
LUA;

    /**
     * @param object $redis a PhpRedisTemplate / PhpRedisSentinelTemplate /
     *        PhpRedisClusterTemplate bean, or a \Redis / \RedisCluster
     */
    public function __construct(
        private readonly object $redis,
        private readonly string $prefix = self::DEFAULT_PREFIX,
    ) {
    }

    public function acquire(string $name, string $owner, int $ttlMs): bool {
        return $this->run(self::ACQUIRE, $name, [$owner, (string)max(0, $ttlMs)]);
    }

    public function release(string $name, string $owner): bool {
        return $this->run(self::RELEASE, $name, [$owner]);
    }

    public function refresh(string $name, string $owner, int $ttlMs): bool {
        return $this->run(self::REFRESH, $name, [$owner, (string)max(0, $ttlMs)]);
    }

    private function run(string $script, string $name, array $args): bool {
        // A connection error throws: a lock that can't be confirmed is not taken.
        $result = $this->redis->eval($script, array_merge([$this->prefix . $name], $args), 1);
        return (int)$result === 1;
    }
}
