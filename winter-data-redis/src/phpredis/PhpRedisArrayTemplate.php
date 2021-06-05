<?php
declare(strict_types=1);


namespace dev\winterframework\data\redis\phpredis;

use RedisArray;

/**
 * @method mixed bgsave()
 * @method mixed del(array $keys)
 * @method mixed discard()
 * @method mixed exec()
 * @method mixed flushall(bool $async)
 * @method mixed flushdb(bool $async)
 * @method mixed getOption(mixed $opt)
 * @method mixed info()
 * @method mixed keys(mixed $pattern)
 * @method mixed mget(array $keys)
 * @method mixed mset(mixed $pairs)
 * @method mixed multi(string $host, mixed $mode)
 * @method mixed ping()
 * @method mixed save()
 * @method mixed select(int $index)
 * @method mixed setOption(mixed $opt, mixed $value)
 * @method mixed unlink()
 * @method mixed unwatch()
 * @method mixed delete(array $keys)
 * @method mixed getMultiple(array $keys)
 *
 */
class PhpRedisArrayTemplate {

    private RedisArray $redis;

    public function __construct(private array $config) {
        $this->redis = new RedisArray(
            $this->config['hosts'],
            $this->config['options'] ? $this->config['options'][0] : []
        );
    }

    public function __call(string $name, array $arguments): mixed {
        return $this->redis->$name(...$arguments);
    }

}