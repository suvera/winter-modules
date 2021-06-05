<?php
declare(strict_types=1);


namespace dev\winterframework\data\redis\phpredis;

use RedisSentinel;

/**
 * @method bool ckquorum(string $value)
 * @method bool failover(string $value)
 * @method bool flushconfig()
 * @method array|false getMasterAddrByName(string $value)
 * @method array|false master(string $value)
 * @method array|false masters()
 * @method bool ping()
 * @method bool reset(string $value)
 * @method array|false sentinels(string $value)
 * @method array|false slaves(string $value)
 */
class PhpRedisSentinelTemplate {

    private RedisSentinel $redis;

    public function __construct(private array $config) {
        $this->redis = new RedisSentinel(
            $this->config['host'],
            $this->config['port'] ?? 6379,
            $this->config['timeout'] ?? 0,
            $this->config['persistent'] ?? null,
            $this->config['retryInterval'] ?? null,
            $this->config['readTimeout'] ?? 0
        );

        $this->redis->ping();
    }

    public function __call(string $name, array $arguments): mixed {
        return $this->redis->$name(...$arguments);
    }

}