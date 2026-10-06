<?php

declare(strict_types=1);

namespace dev\winterframework\data\redis\session;

use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisClusterTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTemplate;
use dev\winterframework\web\session\SessionIdentityStore;
use SessionHandlerInterface;

/**
 * Redis-backed session storage for Winter Boot request sessions
 * (see dev\winterframework\web\session\SessionManager).
 *
 * Each session is a hash at "$keyPrefix . $sessionId" with fields
 * data/username/type. Hash fields update independently, so a write
 * carrying no username leaves the stored name untouched — the same
 * write-once rule as the database store, with no conditional logic.
 * Every write refreshes the key TTL from $ttlSecs (no expiry when
 * $ttlSecs <= 0); expiry is therefore enforced by Redis itself and
 * gc() is a no-op.
 *
 * The client is any PhpRedisAbstractTemplate (single, array, sentinel,
 * cluster or token template — all forward hash commands to phpredis
 * with reconnect handling). Autowire the configured PhpRedisTemplate
 * bean.
 */
class RedisSessionStore implements SessionHandlerInterface, SessionIdentityStore {
    /** KEYS[1] = session key, ARGV[1] = ttl secs, ARGV[2..] = field/value pairs */
    private const WRITE_SCRIPT = <<<'LUA'
for i = 2, #ARGV, 2 do
    redis.call('HSET', KEYS[1], ARGV[i], ARGV[i + 1])
end
local ttl = tonumber(ARGV[1])
if ttl > 0 then
    redis.call('EXPIRE', KEYS[1], ttl)
else
    redis.call('PERSIST', KEYS[1])
end
return 1
LUA;

    public function __construct(
        protected PhpRedisAbstractTemplate $client,
        protected string $keyPrefix = 'wbsess:',
        protected int $ttlSecs = 3600
    ) {
    }

    public function open(string $path, string $name): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read(string $id): string|false {
        $value = $this->client->hGet($this->key($id), 'data');
        return is_string($value) ? $value : '';
    }

    public function readWithIdentity(string $id): array {
        $fields = $this->client->hGetAll($this->key($id));
        if (!is_array($fields) || !isset($fields['data']) || !is_string($fields['data'])) {
            return ['data' => '', 'username' => '', 'sessionType' => 0];
        }
        $username = $fields['username'] ?? '';
        $type = $fields['type'] ?? 0;
        return [
            'data' => $fields['data'],
            'username' => is_string($username) ? $username : '',
            'sessionType' => (int)$type,
        ];
    }

    public function write(string $id, string $data): bool {
        $this->store($id, ['data' => $data]);
        return true;
    }

    public function writeWithIdentity(
        string $id,
        string $data,
        string $username,
        int $sessionType
    ): bool {
        $fields = ['data' => $data, 'type' => (string)$sessionType];
        if ($username !== '') {
            $fields['username'] = $username;
        }
        $this->store($id, $fields);
        return true;
    }

    /**
     * Write the fields and the TTL together. Single-node and cluster
     * templates run one Lua script (atomic: a session hash can never be left
     * without its TTL). Array/token templates route by the first argument,
     * which for EVAL is the script, so they fall back to HMSET + EXPIRE.
     */
    protected function store(string $id, array $fields): void {
        $key = $this->key($id);

        if ($this->client instanceof PhpRedisTemplate || $this->client instanceof PhpRedisClusterTemplate) {
            $args = [$key, strval($this->ttlSecs)];
            foreach ($fields as $field => $value) {
                $args[] = $field;
                $args[] = $value;
            }
            $this->client->eval(self::WRITE_SCRIPT, $args, 1);
            return;
        }

        $this->client->hMset($key, $fields);
        $this->applyTtl($id);
    }

    public function destroy(string $id): bool {
        $this->client->del($this->key($id));
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return 0;
    }

    protected function applyTtl(string $id): void {
        if ($this->ttlSecs > 0) {
            $this->client->expire($this->key($id), $this->ttlSecs);
        } else {
            $this->client->persist($this->key($id));
        }
    }

    protected function key(string $id): string {
        return $this->keyPrefix . $id;
    }
}
