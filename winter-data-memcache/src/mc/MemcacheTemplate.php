<?php
declare(strict_types=1);

namespace dev\winterframework\data\memcache\mc;

/**
 * @method bool setServerParams (string $host, int $port = 11211, int $timeout = 1, int $retry_interval = 15, bool $status = true, callable $failure_callback = null)
 * @method bool add (string $key, mixed $var, int $flag = null, int $expire = null)
 * @method bool close()
 * @method int|false decrement(string $key, int $value = 1)
 * @method bool delete(string $key, int $timeout = 0)
 * @method bool flush()
 * @method string|array get(string|array $key, int|array &$flags = null)
 * @method array getExtendedStats(string $type = null, int $slabId = null, int $limit = 100)
 * @method int getServerStatus(string $host, int $port = 11211)
 * @method array|false getStats(string $type = null, int $slabId = null, int $limit = 100)
 * @method string|false getVersion()
 * @method int|false increment(string $key, int $value = 1)
 * @method bool replace(string $key, mixed $var, int $flag = 0, int $expire = 0)
 * @method bool set(string $key, mixed $var, int $flag = 0, int $expire = 0)
 * @method bool setCompressThreshold(int $threshold, float $min_savings = 0.0)
 */
interface MemcacheTemplate {

}