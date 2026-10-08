<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\cache;

use dev\winterframework\cache\Cache;
use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\SimpleValueWrapper;
use dev\winterframework\cache\ValueRetrievalException;
use dev\winterframework\cache\ValueWrapper;
use dev\winterframework\data\redis\phpredis\PhpRedisAbstractTemplate;
use dev\winterframework\exception\IllegalStateException;
use dev\winterframework\util\SerializationUtil;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class RedisCache implements Cache {
    const PREFIX = 'winter.cache.';
    const KEY_SUFFIX = '.key.';
    private const SERIALIZED_FALSE = 'b:0;';
    protected string $listKey;
    use Wlf4p;

    public function __construct(
        protected PhpRedisAbstractTemplate $client,
        protected string $name,
        protected ?CacheConfiguration $config = null
    ) {
        if (is_null($this->config)) {
            $this->config = new CacheConfiguration();
        }

        $this->listKey = self::PREFIX . $this->name . '.keys';
    }

    protected function buildKey(string $key): string {
        return self::PREFIX . $this->name . self::KEY_SUFFIX . $key;
    }

    public function clear(): void {
        try {
            $keys = $this->client->sMembers($this->listKey);
            if (is_array($keys) && $keys) {
                foreach (array_chunk(array_values($keys), 500) as $chunk) {
                    $this->client->del(...$chunk);
                }
            }
            $this->client->del($this->listKey);
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    public function evict(string $key): bool {
        try {
            $finalKey = $this->buildKey($key);

            $this->client->sRem($this->listKey, $finalKey);
            return intval($this->client->del($finalKey)) > 0;
        } catch (Throwable $e) {
            self::logException($e);
        }
        return false;
    }

    public function has(string $key): bool {
        try {
            return boolval($this->client->exists($this->buildKey($key)));
        } catch (Throwable $e) {
            self::logException($e);
        }
        return false;
    }

    /**
     * Returns SimpleValueWrapper::$NULL_VALUE on a miss (phpredis answers
     * false for an absent key), which CacheableAspect treats as a miss.
     */
    public function get(string $key): ValueWrapper {
        $finalKey = $this->buildKey($key);
        try {
            $data = $this->client->get($finalKey);
        } catch (Throwable $e) {
            self::logException($e);
            return SimpleValueWrapper::$NULL_VALUE;
        }

        if (!is_string($data)) {
            // Expired or never set: drop it from the key index lazily.
            try {
                $this->client->sRem($this->listKey, $finalKey);
            } catch (Throwable) {
            }
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
        $ttl = $this->calcTtl();

        try {
            $finalKey = $this->buildKey($key);

            $this->client->sAdd($this->listKey, $finalKey);
            if ($ttl > 0) {
                $this->client->set($finalKey, serialize($value), ['ex' => $ttl]);
            } else {
                $this->client->set($finalKey, serialize($value));
            }
        } catch (Throwable $e) {
            self::logException($e);
        }
    }

    /**
     * Stores the value only when the key is absent (SET NX) and returns the
     * value now held by the cache.
     */
    public function putIfAbsent(string $key, mixed $value): ValueWrapper {
        if (is_null($value)) {
            return SimpleValueWrapper::$NULL_VALUE;
        }
        $ttl = $this->calcTtl();

        try {
            $finalKey = $this->buildKey($key);

            $this->client->sAdd($this->listKey, $finalKey);
            $options = $ttl > 0 ? ['nx', 'ex' => $ttl] : ['nx'];
            if (!$this->client->set($finalKey, serialize($value), $options)) {
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
