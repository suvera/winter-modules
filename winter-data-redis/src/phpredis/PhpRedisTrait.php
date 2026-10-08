<?php

declare(strict_types=1);

namespace dev\winterframework\data\redis\phpredis;

use RedisException;
use Throwable;

/**
 * Shared call path for templates backed by a RedisConnectionPool.
 *
 * - "<cmd>_xwait" calls retry forever (with backoff) until Redis answers;
 *   meant for blocking queue polls.
 * - Any other call runs once. On a connection error the connection is
 *   discarded, and the command is re-sent on a fresh connection only when it
 *   is read-only: re-sending a write (INCR, XADD, RPUSH, ...) whose reply was
 *   lost could apply it twice.
 */
trait PhpRedisTrait {
    protected RedisConnectionPool $pool;

    /** Read-only commands that are safe to re-send after a connection error (lower-case). */
    private const RETRY_SAFE = [
        'ping' => true, 'echo' => true, 'get' => true, 'mget' => true, 'getmultiple' => true,
        'exists' => true, 'type' => true, 'ttl' => true, 'pttl' => true, 'strlen' => true,
        'getrange' => true, 'getbit' => true, 'bitcount' => true, 'bitpos' => true,
        'keys' => true, 'getkeys' => true, 'scan' => true, 'dbsize' => true, 'info' => true,
        'time' => true, 'lastsave' => true, 'role' => true,
        'hget' => true, 'hgetall' => true, 'hmget' => true, 'hkeys' => true, 'hvals' => true,
        'hlen' => true, 'hexists' => true, 'hstrlen' => true, 'hscan' => true,
        'llen' => true, 'lsize' => true, 'lrange' => true, 'lgetrange' => true, 'lindex' => true, 'lget' => true,
        'smembers' => true, 'sgetmembers' => true, 'scard' => true, 'ssize' => true,
        'sismember' => true, 'scontains' => true, 'srandmember' => true, 'sscan' => true,
        'sinter' => true, 'sunion' => true, 'sdiff' => true,
        'zcard' => true, 'zsize' => true, 'zcount' => true, 'zscore' => true, 'zrank' => true,
        'zrevrank' => true, 'zrange' => true, 'zrevrange' => true, 'zrangebyscore' => true,
        'zrevrangebyscore' => true, 'zrangebylex' => true, 'zrevrangebylex' => true,
        'zlexcount' => true, 'zscan' => true,
        'xlen' => true, 'xrange' => true, 'xrevrange' => true, 'xpending' => true, 'xinfo' => true,
        'pfcount' => true, 'geopos' => true, 'geodist' => true, 'geohash' => true,
        'georadius_ro' => true, 'georadiusbymember_ro' => true, 'dump' => true, 'object' => true,
    ];

    public static function isRetrySafe(string $command): bool {
        return isset(self::RETRY_SAFE[strtolower($command)]);
    }

    /**
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        if (str_ends_with($name, '_xwait')) {
            return $this->callUntilAnswered(substr($name, 0, -6), $arguments);
        }

        $conn = $this->pool->get();
        try {
            return $conn->$name(...$arguments);
        } catch (RedisException $e) {
            $this->pool->invalidate($conn);
            if (!self::isRetrySafe($name)) {
                throw $e;
            }
            self::logDebug('Redis ' . $name . ' failed, retrying on a new connection: ' . $e->getMessage());
            return $this->pool->get()->$name(...$arguments);
        }
    }

    protected function callUntilAnswered(string $name, array $arguments): mixed {
        $waitUs = 0;
        while (true) {
            $conn = null;
            try {
                $conn = $this->pool->get();
                return $conn->$name(...$arguments);
            } catch (RedisException $e) {
                self::logEx($e);
                if ($conn !== null) {
                    $this->pool->invalidate($conn);
                }
                $waitUs = min($waitUs + 200000, 10000000);
                usleep($waitUs);
            }
        }
    }

    public function checkIdleConnection(): void {
        $this->pool->closeIdle();
    }
}
