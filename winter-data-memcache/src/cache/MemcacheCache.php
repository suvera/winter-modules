<?php
declare(strict_types=1);

namespace dev\winterframework\data\memcache\cache;

use dev\winterframework\cache\Cache;
use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\SimpleValueWrapper;
use dev\winterframework\cache\ValueRetrievalException;
use dev\winterframework\cache\ValueWrapper;
use dev\winterframework\data\memcache\mcd\MemcachedTemplate;
use dev\winterframework\exception\IllegalStateException;
use dev\winterframework\util\SerializationUtil;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

/**
 * Cache over Memcached. Entry keys carry a per-cache namespace version;
 * clear() bumps the version, so it only affects this cache (memcached has
 * no prefix delete) and old entries simply age out / get evicted.
 */
class MemcacheCache implements Cache {
    const PREFIX = 'winter.cache.';
    const KEY_SUFFIX = '.key.';
    private const SERIALIZED_FALSE = 'b:0;';
    private const MAX_KEY_LENGTH = 250;
    use Wlf4p;

    public function __construct(
        protected MemcachedTemplate $client,
        protected string $name,
        protected ?CacheConfiguration $config = null
    ) {
        if (is_null($this->config)) {
            $this->config = new CacheConfiguration();
        }
    }

    protected function versionKey(): string {
        return self::PREFIX . $this->name . '.version';
    }

    /**
     * Current namespace version. Seeded from the clock so a version key lost
     * to eviction never comes back with a value used before.
     */
    protected function version(): string {
        $version = $this->client->get($this->versionKey());
        if ($version === false) {
            $this->client->add($this->versionKey(), strval(intval(microtime(true) * 1000)), 0);
            $version = $this->client->get($this->versionKey());
        }
        return strval($version);
    }

    protected function buildKey(string $key): string {
        $finalKey = self::PREFIX . $this->name . '.v' . $this->version() . self::KEY_SUFFIX . $key;
        // memcached keys: max 250 bytes, no whitespace/control characters
        if (strlen($finalKey) > self::MAX_KEY_LENGTH || preg_match('/[\x00-\x20\x7f]/', $finalKey)) {
            $finalKey = self::PREFIX . $this->name . '.v' . $this->version() . '.h.' . hash('sha256', $key);
        }
        return $finalKey;
    }

    public function clear(): void {
        try {
            if ($this->client->increment($this->versionKey()) === false) {
                $this->client->set($this->versionKey(), strval(intval(microtime(true) * 1000)), 0);
            }
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    public function evict(string $key): bool {
        try {
            return boolval($this->client->delete($this->buildKey($key)));
        } catch (Throwable $e) {
            self::logException($e);
        }
        return false;
    }

    public function has(string $key): bool {
        try {
            return $this->client->get($this->buildKey($key)) !== false;
        } catch (Throwable $e) {
            self::logException($e);
        }
        return false;
    }

    /**
     * Returns SimpleValueWrapper::$NULL_VALUE on a miss (memcached answers
     * false for an absent key), which CacheableAspect treats as a miss.
     */
    public function get(string $key): ValueWrapper {
        try {
            $data = $this->client->get($this->buildKey($key));
        } catch (Throwable $e) {
            self::logException($e);
            return SimpleValueWrapper::$NULL_VALUE;
        }

        if (!is_string($data)) {
            return SimpleValueWrapper::$NULL_VALUE;
        }

        $value = SerializationUtil::unserialize($data, true);
        if ($value === false && $data !== self::SERIALIZED_FALSE) {
            self::logWarning('Cache "' . $this->name . '" holds an undecodable entry, treated as a miss');
            return SimpleValueWrapper::$NULL_VALUE;
        }

        return new SimpleValueWrapper($value);
    }

    public function getOrProvide(string $key, callable $valueProvider): ValueWrapper {
        $data = $this->get($key);
        if ($data !== SimpleValueWrapper::$NULL_VALUE) {
            return $data;
        }

        try {
            $value = $valueProvider();
        } catch (Throwable $e) {
            throw new ValueRetrievalException('Provider to cache value is failed for "'
                . $key . '"', 0, $e
            );
        }

        if (is_null($value)) {
            return SimpleValueWrapper::$NULL_VALUE;
        }
        $this->put($key, $value);

        return new SimpleValueWrapper($value);
    }

    public function getAsType(string $key, string $class): ?object {
        $value = $this->get($key);
        if ($value->get() === null) {
            return null;
        }
        if ($value->get() instanceof $class) {
            return $value->get();
        } else {
            throw new IllegalStateException('value in cache is not of type "'
                . $class . '" for key "' . $key . '"'
            );
        }
    }

    public function getName(): string {
        return $this->name;
    }

    public function getNativeCache(): object {
        return $this;
    }

    public function invalidate(): bool {
        $this->clear();
        return true;
    }

    protected function calcTtl(): int {
        $ttl = 0;
        if ($this->config->expireAfterWriteMs > 0) {
            $ttl = intval(ceil($this->config->expireAfterWriteMs / 1000));
        }

        return $ttl;
    }

    public function put(string $key, mixed $value): void {
        if (is_null($value)) {
            return;
        }

        try {
            $this->client->set($this->buildKey($key), serialize($value), $this->calcTtl());
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    /**
     * Stores the value only when the key is absent (memcached ADD) and
     * returns the value now held by the cache.
     */
    public function putIfAbsent(string $key, mixed $value): ValueWrapper {
        if (is_null($value)) {
            return SimpleValueWrapper::$NULL_VALUE;
        }

        try {
            if (!$this->client->add($this->buildKey($key), serialize($value), $this->calcTtl())) {
                $existing = $this->get($key);
                if ($existing !== SimpleValueWrapper::$NULL_VALUE) {
                    return $existing;
                }
            }
        } catch (Throwable $e) {
            self::logException($e);
        }
        return new SimpleValueWrapper($value);
    }

}
