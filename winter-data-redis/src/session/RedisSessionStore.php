<?php

declare(strict_types=1);

namespace dev\winterframework\data\redis\session;

use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
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
    public function __construct(
        private PhpRedisAbstractTemplate $client,
        private string $keyPrefix = 'wbsess:',
        private int $ttlSecs = 3600
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
        $this->client->hSet($this->key($id), 'data', $data);
        $this->applyTtl($id);
        return true;
    }

    public function writeWithIdentity(
        string $id,
        string $data,
        string $username,
        int $sessionType
    ): bool {
        $key = $this->key($id);
        if ($username !== '') {
            $this->client->hSet($key, 'data', $data);
            $this->client->hSet($key, 'username', $username);
            $this->client->hSet($key, 'type', (string)$sessionType);
        } else {
            $this->client->hSet($key, 'data', $data);
            $this->client->hSet($key, 'type', (string)$sessionType);
        }
        $this->applyTtl($id);
        return true;
    }

    public function destroy(string $id): bool {
        $this->client->del($this->key($id));
        return true;
    }

    public function gc(int $max_lifetime): int|false {
        return 0;
    }

    private function applyTtl(string $id): void {
        if ($this->ttlSecs > 0) {
            $this->client->expire($this->key($id), $this->ttlSecs);
        } else {
            $this->client->persist($this->key($id));
        }
    }

    private function key(string $id): string {
        return $this->keyPrefix . $id;
    }
}
