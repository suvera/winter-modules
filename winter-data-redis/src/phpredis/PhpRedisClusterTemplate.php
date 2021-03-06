<?php
/** @noinspection PhpHierarchyChecksInspection */
declare(strict_types=1);

namespace dev\winterframework\data\redis\phpredis;

use dev\winterframework\util\log\Wlf4p;
use RedisCluster;
use RedisException;
use Throwable;

/**
 * @method mixed acl(string $key_or_address, mixed $subcmd, array $args)
 * @method mixed append(string $key, mixed $value)
 * @method mixed bgrewriteaof(string $key_or_address)
 * @method mixed bgsave(string $key_or_address)
 * @method mixed bitcount(string $key)
 * @method mixed bitop(mixed $operation, string $ret_key, string $key, array $other_keys)
 * @method mixed bitpos(string $key, mixed $bit, int $start, int $end)
 * @method mixed blpop(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed brpop(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed brpoplpush(mixed $src, mixed $dst, float $timeout)
 * @method mixed clearlasterror()
 * @method mixed bzpopmax(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed bzpopmin(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed client(string $key_or_address, mixed $arg, array $other_args)
 * @method mixed close()
 * @method mixed cluster(string $key_or_address, mixed $arg, array $other_args)
 * @method mixed command(array $args)
 * @method mixed config(string $key_or_address, mixed $arg, array $other_args)
 * @method mixed dbsize(string $key_or_address)
 * @method mixed decr(string $key)
 * @method mixed decrby(string $key, mixed $value)
 * @method mixed del(string ...$keys)
 * @method mixed discard()
 * @method mixed dump(string $key)
 * @method mixed echo (string $msg)
 * @method mixed eval(mixed $script, array $args, int $num_keys)
 * @method mixed evalsha(mixed $script_sha, array $args, int $num_keys)
 * @method mixed exec()
 * @method mixed exists(string $key)
 * @method mixed expire(string $key, float $timeout)
 * @method mixed expireat(string $key, int $timestamp)
 * @method mixed flushall(string $key_or_address, bool $async)
 * @method mixed flushdb(string $key_or_address, bool $async)
 * @method mixed geoadd(string $key, mixed $lng, mixed $lat, string $member, mixed $other_triples)
 * @method mixed geodist(string $key, mixed $src, mixed $dst, mixed $unit)
 * @method mixed geohash(string $key, string $member, array $other_members)
 * @method mixed geopos(string $key, string $member, array $other_members)
 * @method mixed georadius(string $key, mixed $lng, mixed $lan, mixed $radius, mixed $unit, array $opts)
 * @method mixed georadius_ro(string $key, mixed $lng, mixed $lan, mixed $radius, mixed $unit, array $opts)
 * @method mixed georadiusbymember(string $key, string $member, mixed $radius, mixed $unit, array $opts)
 * @method mixed georadiusbymember_ro(string $key, string $member, mixed $radius, mixed $unit, array $opts)
 * @method mixed get(string $key)
 * @method mixed getbit(string $key, int $offset)
 * @method mixed getlasterror()
 * @method mixed getmode()
 * @method mixed getoption(mixed $option)
 * @method mixed getrange(string $key, int $start, int $end)
 * @method mixed getset(string $key, mixed $value)
 * @method mixed hdel(string $key, string $member, array $other_members)
 * @method mixed hexists(string $key, string $member)
 * @method mixed hget(string $key, string $member)
 * @method mixed hgetall(string $key)
 * @method mixed hincrby(string $key, string $member, mixed $value)
 * @method mixed hincrbyfloat(string $key, string $member, mixed $value)
 * @method mixed hkeys(string $key)
 * @method mixed hlen(string $key)
 * @method mixed hmget(string $key, array $keys)
 * @method mixed hmset(string $key, array $pairs)
 * @method mixed hscan(string $str_key, mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method mixed hset(string $key, string $member, mixed $value)
 * @method mixed hsetnx(string $key, string $member, mixed $value)
 * @method mixed hstrlen(string $key, string $member)
 * @method mixed hvals(string $key)
 * @method int incr(string $key)
 * @method mixed incrby(string $key, mixed $value)
 * @method mixed incrbyfloat(string $key, mixed $value)
 * @method mixed info(string $key_or_address, mixed $option)
 * @method mixed keys(mixed $pattern)
 * @method mixed lastsave(string $key_or_address)
 * @method mixed lget(string $key, int $index)
 * @method mixed lindex(string $key, int $index)
 * @method mixed linsert(string $key, mixed $position, mixed $pivot, mixed $value)
 * @method mixed llen(string $key)
 * @method mixed lpop(string $key)
 * @method mixed lpush(string $key, mixed $value)
 * @method mixed lpushx(string $key, mixed $value)
 * @method mixed lrange(string $key, int $start, int $end)
 * @method mixed lrem(string $key, mixed $value)
 * @method mixed lset(string $key, int $index, mixed $value)
 * @method mixed ltrim(string $key, int $start, mixed $stop)
 * @method mixed mget(array $keys)
 * @method mixed mset(array $pairs)
 * @method mixed msetnx(array $pairs)
 * @method mixed multi()
 * @method mixed object(mixed $field, string $key)
 * @method mixed persist(string $key)
 * @method mixed pexpire(string $key, int $timestamp)
 * @method mixed pexpireat(string $key, int $timestamp)
 * @method mixed pfadd(string $key, array $elements)
 * @method mixed pfcount(string $key)
 * @method mixed pfmerge(string $dstkey, array $keys)
 * @method mixed ping(string $key_or_address)
 * @method mixed psetex(string $key, int $expire, mixed $value)
 * @method mixed psubscribe(array $patterns, mixed $callback)
 * @method mixed pttl(string $key)
 * @method mixed publish(mixed $channel, string $message)
 * @method mixed pubsub(string $key_or_address, mixed $arg, array $other_args)
 * @method mixed punsubscribe(mixed $pattern, mixed $other_patterns)
 * @method mixed randomkey(string $key_or_address)
 * @method mixed rawcommand(mixed $cmd, array $args)
 * @method mixed rename(string $key, string $newkey)
 * @method mixed renamenx(string $key, string $newkey)
 * @method mixed restore(int $ttl, string $key, mixed $value)
 * @method mixed role()
 * @method mixed rpop(string $key)
 * @method mixed rpoplpush(mixed $src, mixed $dst)
 * @method mixed rpush(string $key, mixed $value)
 * @method mixed rpushx(string $key, mixed $value)
 * @method mixed sadd(string $key, mixed $value)
 * @method mixed saddarray(string $key, array $options)
 * @method mixed save(string $key_or_address)
 * @method mixed scan(mixed $i_iterator, mixed $str_node, mixed $str_pattern, mixed $i_count)
 * @method mixed scard(string $key)
 * @method mixed script(string $key_or_address, mixed $arg, array $other_args)
 * @method mixed sdiff(string $key, array $other_keys)
 * @method mixed sdiffstore(mixed $dst, string $key, array $other_keys)
 * @method mixed set(string $key, mixed $value, mixed $opts)
 * @method mixed setbit(string $key, int $offset, mixed $value)
 * @method mixed setex(string $key, int $expire, mixed $value)
 * @method mixed setnx(string $key, mixed $value)
 * @method mixed setoption(mixed $option, mixed $value)
 * @method mixed setrange(string $key, int $offset, mixed $value)
 * @method mixed sinter(string $key, array $other_keys)
 * @method mixed sinterstore(mixed $dst, string $key, array $other_keys)
 * @method mixed sismember(string $key, mixed $value)
 * @method mixed slowlog(string $key_or_address, mixed $arg, array $other_args)
 * @method mixed smembers(string $key)
 * @method mixed smove(mixed $src, mixed $dst, mixed $value)
 * @method mixed sort(string $key, array $options)
 * @method mixed spop(string $key)
 * @method mixed srandmember(string $key, int $count)
 * @method mixed srem(string $key, mixed $value)
 * @method mixed sscan(string $str_key, mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method mixed strlen(string $key)
 * @method mixed subscribe(array $channels, mixed $callback)
 * @method mixed sunion(string $key, array $other_keys)
 * @method mixed sunionstore(mixed $dst, string $key, array $other_keys)
 * @method mixed time()
 * @method mixed ttl(string $key)
 * @method mixed type(string $key)
 * @method mixed unsubscribe(mixed $channel, mixed $other_channels)
 * @method mixed unlink(string $key, array $other_keys)
 * @method mixed unwatch()
 * @method mixed watch(string $key, array $other_keys)
 * @method mixed xack(string $str_key, mixed $str_group, array $arr_ids)
 * @method mixed xadd(string $str_key, mixed $str_id, array $arr_fields, mixed $i_maxlen, mixed $boo_approximate)
 * @method mixed xclaim(string $str_key, mixed $str_group, mixed $str_consumer, mixed $i_min_idle, array $arr_ids, array $arr_opts)
 * @method mixed xdel(string $str_key, array $arr_ids)
 * @method mixed xgroup(mixed $str_operation, string $str_key, mixed $str_arg1, mixed $str_arg2, mixed $str_arg3)
 * @method mixed xinfo(mixed $str_cmd, string $str_key, mixed $str_group)
 * @method mixed xlen(string $key)
 * @method mixed xpending(string $str_key, mixed $str_group, mixed $str_start, mixed $str_end, mixed $i_count, mixed $str_consumer)
 * @method mixed xrange(string $str_key, mixed $str_start, mixed $str_end, mixed $i_count)
 * @method mixed xread(array $arr_streams, mixed $i_count, mixed $i_block)
 * @method mixed xreadgroup(mixed $str_group, mixed $str_consumer, array $arr_streams, mixed $i_count, mixed $i_block)
 * @method mixed xrevrange(string $str_key, mixed $str_start, mixed $str_end, mixed $i_count)
 * @method mixed xtrim(string $str_key, mixed $i_maxlen, mixed $boo_approximate)
 * @method mixed zadd(string $key, mixed $score, mixed $value, array $extra_args)
 * @method mixed zcard(string $key)
 * @method mixed zcount(string $key, mixed $min, mixed $max)
 * @method mixed zincrby(string $key, mixed $value, string $member)
 * @method mixed zinterstore(string $key, array $keys, array $weights, mixed $aggregate)
 * @method mixed zlexcount(string $key, mixed $min, mixed $max)
 * @method mixed zpopmax(string $key)
 * @method mixed zpopmin(string $key)
 * @method mixed zrange(string $key, int $start, int $end, mixed $scores)
 * @method mixed zrangebylex(string $key, mixed $min, mixed $max, int $offset, mixed $limit)
 * @method mixed zrangebyscore(string $key, int $start, int $end, array $options)
 * @method mixed zrank(string $key, string $member)
 * @method mixed zrem(string $key, string $member, array $other_members)
 * @method mixed zremrangebylex(string $key, mixed $min, mixed $max)
 * @method mixed zremrangebyrank(string $key, mixed $min, mixed $max)
 * @method mixed zremrangebyscore(string $key, mixed $min, mixed $max)
 * @method mixed zrevrange(string $key, int $start, int $end, mixed $scores)
 * @method mixed zrevrangebylex(string $key, mixed $min, mixed $max, int $offset, mixed $limit)
 * @method mixed zrevrangebyscore(string $key, int $start, int $end, array $options)
 * @method mixed zrevrank(string $key, string $member)
 * @method mixed zscan(string $str_key, mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method mixed zscore(string $key, string $member)
 * @method mixed zunionstore(string $key, array $keys, array $weights, mixed $aggregate)
 */
class PhpRedisClusterTemplate implements PhpRedisAbstractTemplate {
    use Wlf4p;
    use PhpRedisTrait;

    protected ?RedisCluster $redis;

    public function __construct(private array $config) {
        $this->idleTimeout = $this->config['idleTimeout'] ?? 0;
        $this->lastAccessTime = time();
        $this->lastIdleCheck = time();
        $this->reConnect();
    }

    /**
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $this->lastAccessTime = time();

        if (is_null($this->redis)) {
            $this->reConnect();
        }

        try {
            return $this->redis->$name(...$arguments);
        } catch (Throwable $e) {
            self::logEx($e);

            if (substr($name, -6) == '_xwait') {
                $funcName = substr($name, 0, -6);
                $waitMs = 0;
                while (1) {
                    $this->lastAccessTime = time();
                    if ($waitMs < 10000000) {
                        $waitMs += 200000;
                    }

                    try {
                        if (is_null($this->redis)) {
                            $this->reConnect();
                        }
                        return $this->redis->$funcName(...$arguments);
                    } catch (RedisException $e) {
                        self::logEx($e);
                        usleep($waitMs);
                    } catch (Throwable $e) {
                        self::logEx($e);
                        if (!is_null($this->redis)) {
                            break;
                        }
                    }
                }
            }

            $this->reConnect();
            return $this->redis->$name(...$arguments);
        }
    }

    protected function reConnect(): void {
        $this->lastAccessTime = time();

        $this->redis = new RedisCluster(
            null,
            $this->config['hosts'],
            $this->config['timeout'] ?? 0,
            $this->config['readTimeout'] ?? 0,
            $this->config['persistent'] ?? false,
            $this->config['auth'] ?? null
        );
    }
}