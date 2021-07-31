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
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class MemcacheCache implements Cache {
    const PREFIX = 'winter.cache.';
    const KEY_SUFFIX = '.key.';
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

    protected function buildKey(string $key): string {
        return self::PREFIX . $this->name . self::KEY_SUFFIX . $key;
    }

    public function clear(): void {
        try {
            $keys = $this->client->getAllKeys();

            foreach ($keys as $key) {
                $this->client->delete($key);
            }
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    public function evict(string $key): bool {
        try {
            $finalKey = $this->buildKey($key);
            return $this->client->delete($finalKey);
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

    public function get(string $key): ValueWrapper {
        $data = null;
        try {
            $data = $this->client->get($this->buildKey($key));
        } catch (Throwable $e) {
            self::logException($e);
        }
        return is_null($data) ? SimpleValueWrapper::$NULL_VALUE : new SimpleValueWrapper($data);
    }

    public function getOrProvide(string $key, callable $valueProvider): ValueWrapper {
        $data = $this->get($key);
        $value = null;
        if (is_null($data) && $data !== false) {
            try {
                $value = $valueProvider();
                if (!is_null($value)) {
                    $this->put($key, $value);
                }
            } catch (Throwable $e) {
                throw new ValueRetrievalException('Provider to cache value is failed for "'
                    . $key . '"', 0, $e
                );
            }
        }
        return is_null($value) ? SimpleValueWrapper::$NULL_VALUE : new SimpleValueWrapper($value);
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
        $ttl = $this->calcTtl();

        $value = serialize($value);
        try {
            $finalKey = $this->buildKey($key);
            $this->client->set($finalKey, $value, $ttl);
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    public function putIfAbsent(string $key, mixed $value): ValueWrapper {
        if (is_null($value)) {
            return SimpleValueWrapper::$NULL_VALUE;
        }
        $ttl = $this->calcTtl();
        $value = serialize($value);

        try {
            $finalKey = $this->buildKey($key);

            if ($this->client->get($finalKey) !== false) {
                $this->client->set($finalKey, $value, $ttl);
            }
        } catch (Throwable $e) {
            self::logException($e);
        }
        return new SimpleValueWrapper($value);
    }

}