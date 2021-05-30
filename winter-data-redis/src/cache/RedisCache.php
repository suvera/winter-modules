<?php
namespace dev\winterframework\data\redis\cache;

use dev\winterframework\cache\Cache;
use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\SimpleValueWrapper;
use dev\winterframework\cache\ValueRetrievalException;
use dev\winterframework\cache\ValueWrapper;
use dev\winterframework\data\redis\core\RedisTemplate;
use dev\winterframework\exception\IllegalStateException;
use Throwable;

class RedisCache implements Cache {

    private string $name;
    private CacheConfiguration $config;
    private RedisTemplate $redisTemplate;

    public function __construct(
        string $name,
        RedisTemplate $redisTemplate,
        ?CacheConfiguration $config = null
    ) {
        $this->name = $name;
        $this->redisTemplate = $redisTemplate;
        $this->config = (!is_null($config) ? $config : new CacheConfiguration());
    }


    public function clear(): void {
        // TODO: Implement clear() method.
    }

    public function evict(string $key): bool {
        $this->redisTemplate->delete($key);
        return true;
    }

    public function has(string $key): bool {
        return $this->redisTemplate->exists($key);
    }

    public function get(string $key): ValueWrapper {
        return new SimpleValueWrapper($this->redisTemplate->get($key));
    }

    public function getOrProvide(string $key, callable $valueProvider): ValueWrapper {
        if ($this->redisTemplate->exists($key)) {
            $val = $this->redisTemplate->get($key);
        } else {
            try {
                $val = $valueProvider();
            } catch (Throwable $e) {
                throw new ValueRetrievalException('Provider to cache value is failed for "'
                    . $key . '"', 0, $e
                );
            }
        }
        return new SimpleValueWrapper($val);
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
        // TODO: Implement invalidate() method.
    }

    public function put(string $key, mixed $value): void {
        $this->redisTemplate->set($key, $value);
    }

    public function putIfAbsent(string $key, mixed $value): ValueWrapper {
        if ($this->has($key)) {
            $val = $this->redisTemplate->getAndSet($key, $value);
            return new SimpleValueWrapper($val);
        } else {
            $this->redisTemplate->set($key, $value);
            return SimpleValueWrapper::$NULL_VALUE;
        }
    }

}