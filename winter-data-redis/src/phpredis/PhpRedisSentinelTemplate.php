<?php

declare(strict_types=1);


namespace dev\winterframework\data\redis\phpredis;

use dev\winterframework\util\log\Wlf4p;
use ReflectionMethod;
use ReflectionNamedType;
use RedisException;
use RedisSentinel;

/**
 * Sentinel commands (master, getMasterAddrByName, ...). Not a data template,
 * so it does not implement PhpRedisAbstractTemplate.
 */
class PhpRedisSentinelTemplate {
    use Wlf4p;

    protected RedisConnectionPool $pool;

    public function __construct(private array $config) {
        $this->pool = RedisConnectionPool::fromConfig(
            fn(string $persistentId): RedisSentinel => $this->connect($persistentId),
            'sentinel-' . ($this->config['name'] ?? ''),
            $this->config
        );
        $this->pool->get();
    }

    protected function connect(string $persistentId): RedisSentinel {
        $persistent = empty($this->config['persistent'] ?? $this->config['persistence'] ?? false) ? null : $persistentId;

        if (self::takesOptionsArray()) {
            // phpredis >= 6: single options array
            $options = [
                'host' => strval($this->config['host']),
                'port' => intval($this->config['port'] ?? 6379),
                'connectTimeout' => floatval($this->config['timeout'] ?? 0),
                'retryInterval' => intval($this->config['retryInterval'] ?? 0),
                'readTimeout' => floatval($this->config['readTimeout'] ?? 0),
            ];
            if ($persistent !== null) {
                $options['persistent'] = $persistent;
            }
            if (isset($this->config['auth'])) {
                $options['auth'] = $this->config['auth'];
            }
            return new RedisSentinel($options);
        }

        // phpredis 5.x positional constructor
        /** @noinspection PhpMethodParametersCountMismatchInspection */
        return new RedisSentinel(
            strval($this->config['host']),
            intval($this->config['port'] ?? 6379),
            floatval($this->config['timeout'] ?? 0),
            $persistent,
            intval($this->config['retryInterval'] ?? 0),
            floatval($this->config['readTimeout'] ?? 0)
        );
    }

    private static function takesOptionsArray(): bool {
        static $result = null;
        if ($result === null) {
            $params = (new ReflectionMethod(RedisSentinel::class, '__construct'))->getParameters();
            $type = isset($params[0]) ? $params[0]->getType() : null;
            $result = $type instanceof ReflectionNamedType && $type->getName() === 'array';
        }
        return $result;
    }

    /**
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $conn = $this->pool->get();
        try {
            return $conn->$name(...$arguments);
        } catch (RedisException $e) {
            $this->pool->invalidate($conn);
            if (in_array(strtolower($name), ['failover', 'reset', 'flushconfig'], true)) {
                throw $e;
            }
            self::logDebug('Redis sentinel ' . $name . ' failed, retrying: ' . $e->getMessage());
            return $this->pool->get()->$name(...$arguments);
        }
    }

    public function checkIdleConnection(): void {
        $this->pool->closeIdle();
    }
}
