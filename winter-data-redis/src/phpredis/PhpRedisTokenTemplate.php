<?php

namespace dev\winterframework\data\redis\phpredis;

use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\hash\HashProvider;
use dev\winterframework\util\hash\MurmurHash3Provider;
use dev\winterframework\util\log\Wlf4p;
use Redis;
use RedisException;
use Throwable;

/**
 * @method mixed acl(mixed $subcmd, array $args)
 * @method mixed append(string $key, mixed $value)
 * @method mixed auth(mixed $auth)
 * @method mixed bgSave()
 * @method mixed bgrewriteaof()
 * @method mixed bitcount(string $key)
 * @method mixed bitop(mixed $operation, string $ret_key, string $key, array $other_keys)
 * @method mixed bitpos(string $key, mixed $bit, int $start, int $end)
 * @method mixed blPop(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed brPop(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed brpoplpush(mixed $src, mixed $dst, float $timeout)
 * @method mixed bzPopMax(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed bzPopMin(string $key, float $timeout_or_key, array $extra_args)
 * @method mixed clearLastError()
 * @method mixed client(mixed $cmd, array $args)
 * @method mixed close()
 * @method mixed command(array $args)
 * @method mixed config(mixed $cmd, string $key, mixed $value)
 * @method mixed connect(string $host, int $port, float $timeout, float $retry_interval)
 * @method mixed dbSize()
 * @method mixed debug(string $key)
 * @method mixed decr(string $key)
 * @method mixed decrBy(string $key, mixed $value)
 * @method mixed del(string ...$keys)
 * @method mixed discard()
 * @method mixed dump(string $key)
 * @method mixed echo (string $msg)
 * @method mixed eval(mixed $script, array $args, int $num_keys)
 * @method mixed evalsha(mixed $script_sha, array $args, int $num_keys)
 * @method mixed exec()
 * @method mixed exists(string $key, array $other_keys)
 * @method mixed expire(string $key, float $timeout)
 * @method mixed expireAt(string $key, int $timestamp)
 * @method mixed flushAll(bool $async)
 * @method mixed flushDB(bool $async)
 * @method mixed geoadd(string $key, mixed $lng, mixed $lat, string $member, mixed $other_triples)
 * @method mixed geodist(string $key, mixed $src, mixed $dst, mixed $unit)
 * @method mixed geohash(string $key, string $member, array $other_members)
 * @method mixed geopos(string $key, string $member, array $other_members)
 * @method mixed georadius(string $key, mixed $lng, mixed $lan, mixed $radius, mixed $unit, array $opts)
 * @method mixed georadius_ro(string $key, mixed $lng, mixed $lan, mixed $radius, mixed $unit, array $opts)
 * @method mixed georadiusbymember(string $key, string $member, mixed $radius, mixed $unit, array $opts)
 * @method mixed georadiusbymember_ro(string $key, string $member, mixed $radius, mixed $unit, array $opts)
 * @method mixed get(string $key)
 * @method mixed getAuth()
 * @method mixed getBit(string $key, int $offset)
 * @method mixed getDBNum()
 * @method mixed getHost()
 * @method mixed getLastError()
 * @method mixed getMode()
 * @method mixed getOption(mixed $option)
 * @method mixed getPersistentID()
 * @method mixed getPort()
 * @method mixed getRange(string $key, int $start, int $end)
 * @method mixed getReadTimeout()
 * @method mixed getSet(string $key, mixed $value)
 * @method mixed getTimeout()
 * @method mixed hDel(string $key, string $member, array $other_members)
 * @method mixed hExists(string $key, string $member)
 * @method mixed hGet(string $key, string $member)
 * @method mixed hGetAll(string $key)
 * @method mixed hIncrBy(string $key, string $member, mixed $value)
 * @method mixed hIncrByFloat(string $key, string $member, mixed $value)
 * @method mixed hKeys(string $key)
 * @method mixed hLen(string $key)
 * @method mixed hMget(string $key, array $keys)
 * @method mixed hMset(string $key, array $pairs)
 * @method mixed hSet(string $key, string $member, mixed $value)
 * @method mixed hSetNx(string $key, string $member, mixed $value)
 * @method mixed hStrLen(string $key, string $member)
 * @method mixed hVals(string $key)
 * @method mixed hscan(string $str_key, mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method int incr(string $key)
 * @method mixed incrBy(string $key, mixed $value)
 * @method mixed incrByFloat(string $key, mixed $value)
 * @method mixed info(mixed $option)
 * @method mixed isConnected()
 * @method mixed keys(mixed $pattern)
 * @method mixed lInsert(string $key, mixed $position, mixed $pivot, mixed $value)
 * @method mixed lLen(string $key)
 * @method mixed lPop(string $key)
 * @method mixed lPush(string $key, mixed $value)
 * @method mixed lPushx(string $key, mixed $value)
 * @method mixed lSet(string $key, int $index, mixed $value)
 * @method mixed lastSave()
 * @method mixed lindex(string $key, int $index)
 * @method mixed lrange(string $key, int $start, int $end)
 * @method mixed lrem(string $key, mixed $value, int $count)
 * @method mixed ltrim(string $key, int $start, mixed $stop)
 * @method mixed mget(array $keys)
 * @method mixed migrate(string $host, int $port, string $key, mixed $db, float $timeout, mixed $copy, mixed $replace)
 * @method mixed move(string $key, int $dbindex)
 * @method mixed mset(array $pairs)
 * @method mixed msetnx(array $pairs)
 * @method mixed multi(mixed $mode)
 * @method mixed object(mixed $field, string $key)
 * @method mixed pconnect(string $host, int $port, float $timeout)
 * @method mixed persist(string $key)
 * @method mixed pexpire(string $key, int $timestamp)
 * @method mixed pexpireAt(string $key, int $timestamp)
 * @method mixed pfadd(string $key, array $elements)
 * @method mixed pfcount(string $key)
 * @method mixed pfmerge(string $dstkey, array $keys)
 * @method mixed ping()
 * @method mixed pipeline()
 * @method mixed psetex(string $key, int $expire, mixed $value)
 * @method mixed psubscribe(array $patterns, mixed $callback)
 * @method mixed pttl(string $key)
 * @method mixed publish(mixed $channel, string $message)
 * @method mixed pubsub(mixed $cmd, array $args)
 * @method mixed punsubscribe(mixed $pattern, mixed $other_patterns)
 * @method mixed rPop(string $key)
 * @method mixed rPush(string $key, mixed $value)
 * @method mixed rPushx(string $key, mixed $value)
 * @method mixed randomKey()
 * @method mixed rawcommand(mixed $cmd, array $args)
 * @method mixed rename(string $key, string $newkey)
 * @method mixed renameNx(string $key, string $newkey)
 * @method mixed restore(int $ttl, string $key, mixed $value)
 * @method mixed role()
 * @method mixed rpoplpush(mixed $src, mixed $dst)
 * @method mixed sAdd(string $key, mixed $value)
 * @method mixed sAddArray(string $key, array $options)
 * @method mixed sDiff(string $key, array $other_keys)
 * @method mixed sDiffStore(mixed $dst, string $key, array $other_keys)
 * @method mixed sInter(string $key, array $other_keys)
 * @method mixed sInterStore(mixed $dst, string $key, array $other_keys)
 * @method mixed sMembers(string $key)
 * @method mixed sMove(mixed $src, mixed $dst, mixed $value)
 * @method mixed sPop(string $key)
 * @method mixed sRandMember(string $key, int $count)
 * @method mixed sUnion(string $key, array $other_keys)
 * @method mixed sUnionStore(mixed $dst, string $key, array $other_keys)
 * @method mixed save()
 * @method mixed scan(mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method mixed scard(string $key)
 * @method mixed script(mixed $cmd, array $args)
 * @method mixed select(int $dbindex)
 * @method mixed set(string $key, mixed $value, mixed $opts)
 * @method mixed setBit(string $key, int $offset, mixed $value)
 * @method mixed setOption(mixed $option, mixed $value)
 * @method mixed setRange(string $key, int $offset, mixed $value)
 * @method mixed setex(string $key, int $expire, mixed $value)
 * @method mixed setnx(string $key, mixed $value)
 * @method mixed sismember(string $key, mixed $value)
 * @method mixed slaveof(string $host, int $port)
 * @method mixed slowlog(mixed $arg, mixed $option)
 * @method mixed sort(string $key, array $options)
 * @method mixed sortAsc(string $key, mixed $pattern, mixed $get, int $start, int $end, mixed $getList)
 * @method mixed sortAscAlpha(string $key, mixed $pattern, mixed $get, int $start, int $end, mixed $getList)
 * @method mixed sortDesc(string $key, mixed $pattern, mixed $get, int $start, int $end, mixed $getList)
 * @method mixed sortDescAlpha(string $key, mixed $pattern, mixed $get, int $start, int $end, mixed $getList)
 * @method mixed srem(string $key, string $member, array $other_members)
 * @method mixed sscan(string $str_key, mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method mixed strlen(string $key)
 * @method mixed subscribe(array $channels, mixed $callback)
 * @method mixed swapdb(mixed $srcdb, mixed $dstdb)
 * @method mixed time()
 * @method mixed ttl(string $key)
 * @method mixed type(string $key)
 * @method mixed unlink(string $key, array $other_keys)
 * @method mixed unsubscribe(mixed $channel, mixed $other_channels)
 * @method mixed unwatch()
 * @method mixed wait(mixed $numslaves, float $timeout)
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
 * @method mixed zAdd(string $key, mixed $score, mixed $value, array $extra_args)
 * @method mixed zCard(string $key)
 * @method mixed zCount(string $key, mixed $min, mixed $max)
 * @method mixed zIncrBy(string $key, mixed $value, string $member)
 * @method mixed zLexCount(string $key, mixed $min, mixed $max)
 * @method mixed zPopMax(string $key)
 * @method mixed zPopMin(string $key)
 * @method mixed zRange(string $key, int $start, int $end, mixed $scores)
 * @method mixed zRangeByLex(string $key, mixed $min, mixed $max, int $offset, mixed $limit)
 * @method mixed zRangeByScore(string $key, int $start, int $end, array $options)
 * @method mixed zRank(string $key, string $member)
 * @method mixed zRem(string $key, string $member, array $other_members)
 * @method mixed zRemRangeByLex(string $key, mixed $min, mixed $max)
 * @method mixed zRemRangeByRank(string $key, int $start, int $end)
 * @method mixed zRemRangeByScore(string $key, mixed $min, mixed $max)
 * @method mixed zRevRange(string $key, int $start, int $end, mixed $scores)
 * @method mixed zRevRangeByLex(string $key, mixed $min, mixed $max, int $offset, mixed $limit)
 * @method mixed zRevRangeByScore(string $key, int $start, int $end, array $options)
 * @method mixed zRevRank(string $key, string $member)
 * @method mixed zScore(string $key, string $member)
 * @method mixed zinterstore(string $key, array $keys, array $weights, mixed $aggregate)
 * @method mixed zscan(string $str_key, mixed $i_iterator, mixed $str_pattern, mixed $i_count)
 * @method mixed zunionstore(string $key, array $keys, array $weights, mixed $aggregate)
 * @method mixed delete(string $key, array $other_keys)
 * @method mixed evaluate(mixed $script, array $args, int $num_keys)
 * @method mixed evaluateSha(mixed $script_sha, array $args, int $num_keys)
 * @method mixed getKeys(mixed $pattern)
 * @method mixed getMultiple(array $keys)
 * @method mixed lGet(string $key, int $index)
 * @method mixed lGetRange(string $key, int $start, int $end)
 * @method mixed lRemove(string $key, mixed $value, int $count)
 * @method mixed lSize(string $key)
 * @method mixed listTrim(string $key, int $start, mixed $stop)
 * @method mixed open(string $host, int $port, float $timeout, float $retry_interval)
 * @method mixed popen(string $host, int $port, float $timeout)
 * @method mixed renameKey(string $key, string $newkey)
 * @method mixed sContains(string $key, mixed $value)
 * @method mixed sGetMembers(string $key)
 * @method mixed sRemove(string $key, string $member, array $other_members)
 * @method mixed sSize(string $key)
 * @method mixed sendEcho(string $msg)
 * @method mixed setTimeout(string $key, float $timeout)
 * @method mixed substr(string $key, int $start, int $end)
 * @method mixed zDelete(string $key, string $member, array $other_members)
 * @method mixed zDeleteRangeByRank(string $key, mixed $min, mixed $max)
 * @method mixed zDeleteRangeByScore(string $key, mixed $min, mixed $max)
 * @method mixed zInter(string $key, array $keys, array $weights, mixed $aggregate)
 * @method mixed zRemove(string $key, string $member, array $other_members)
 * @method mixed zRemoveRangeByScore(string $key, mixed $min, mixed $max)
 * @method mixed zReverseRange(string $key, int $start, int $end, mixed $scores)
 * @method mixed zSize(string $key)
 * @method mixed zUnion(string $key, array $keys, array $weights, mixed $aggregate)
 */
class PhpRedisTokenTemplate implements PhpRedisAbstractTemplate {
    use Wlf4p;

    /**
     * @var Redis[][]
     */
    protected array $redis = [];
    /**
     * @var Redis[][]
     */
    private array $tokens = [];
    private array $hosts = [];
    private bool $strictTokenRing = false;
    private HashProvider $hashProvider;
    protected int $idleTimeout = 0;

    public function __construct(private array $config) {
        $this->redis = [];
        $this->idleTimeout = $this->config['idleTimeout'] ?? 0;
        $this->init();
    }

    private function reconnect(string $hostName): void {
        $connect = isset($this->config['persistence']) && $this->config['persistence'] ? 'pconnect' : 'connect';
        $host = $this->hosts[$hostName];

        $redis = new Redis();

        $redis->$connect(
            $host['host'],
            $host['port'],
            $host['timeout'],
            $host['reserved'],
            $host['retryInterval'],
            $host['readTimeout']
        );

        $this->redis[$host['host']] = [
            'lastAccessTime' => time(),
            'lastIdleCheck' => time(),
            'conn' => $redis
        ];
    }

    private function init(): void {
        TypeAssert::array($this->config['hosts'], " 'hosts' config must be array");
        $this->strictTokenRing = $this->config['strictTokenRing'] ?? false;
        $hp = $this->config['hashProvider'] ?? MurmurHash3Provider::class;
        $this->hashProvider = new $hp();

        $this->config['timeout'] = $this->config['timeout'] ?? 0;
        $this->config['reserved'] = $this->config['reserved'] ?? null;
        $this->config['retryInterval'] = $this->config['retryInterval'] ?? null;
        $this->config['readTimeout'] = $this->config['readTimeout'] ?? 0;

        foreach ($this->config['hosts'] as $host) {
            TypeAssert::array($host, " 'hosts' config must be array");
            TypeAssert::integer($host['token'], " empty value 'hosts -> token' ");
            TypeAssert::string($host['host'], " empty value 'hosts -> host' ");

            $this->hosts[$host['host']] = [
                'host' => $host['host'],
                'port' => $host['port'] ?? 6379,
                'timeout' => $this->config['timeout'],
                'reserved' => $this->config['reserved'],
                'retryInterval' => $this->config['retryInterval'],
                'readTimeout' => $this->config['readTimeout']
            ];

            $this->tokens[$host['token']][$host['host']] = $host['host'];
        }
        ksort($this->tokens, SORT_NUMERIC);
    }

    /**
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $hash = 0;
        if (isset($arguments[0]) && is_scalar($arguments[0])) {
            $hash = $this->hashProvider->getHashInt($arguments[0]);
        }

        $redisHosts = null;
        foreach ($this->tokens as $token => $redisHosts) {
            if ($hash < $token) {
                break;
            }
        }

        if (!$this->strictTokenRing) {
            foreach ($this->hosts as $hostName => $config) {
                if (!isset($redisHosts[$hostName])) {
                    $redisHosts[$hostName] = $hostName;
                }
            }
        }

        if (substr($name, -6) == '_xwait') {
            $funcName = substr($name, 0, -6);
            $waitMs = 0;
            while (1) {
                if ($waitMs < 10000000) {
                    $waitMs += 200000;
                }

                $redis = null;
                $hostName = null;
                try {
                    foreach ($redisHosts as $hostName) {
                        try {
                            if (!isset($this->redis[$hostName]) || !$this->redis[$hostName]['conn']->isConnected()) {
                                $this->reconnect($hostName);
                            }
                            $redis = $this->redis[$hostName]['conn'];
                            break;
                        } catch (Throwable $e) {
                            self::logDebug($e->getMessage());
                        }
                    }

                    $value = $redis->$funcName(...$arguments);
                    $this->redis[$hostName]['lastAccessTime'] = time();

                    return $value;
                } catch (RedisException $e) {
                    self::logEx($e);
                    usleep($waitMs);
                    unset($this->redis[$hostName]);
                } catch (Throwable $e) {
                    self::logEx($e);
                    throw $e;
                }
            }
        }

        $redis = null;
        $hostName = null;
        foreach ($redisHosts as $hostName) {
            if (!isset($this->redis[$hostName]) || !$this->redis[$hostName]['conn']->isConnected()) {
                $this->reconnect($hostName);
            }
            $redis = $this->redis[$hostName]['conn'];
            break;
        }

        try {
            $value = $redis->$name(...$arguments);
        } catch (RedisException $e) {
            self::logDebug($e->getMessage());
            unset($this->redis[$hostName]);
            $this->reconnect($hostName);
            $redis = $this->redis[$hostName]['conn'];
            $value = $redis->$name(...$arguments);
        }
        $this->redis[$hostName]['lastAccessTime'] = time();
        return $value;
    }

    public function checkIdleConnection(): void {

        foreach ($this->redis as $hostName => $data) {
            if ($data['lastAccessTime'] == 0 || $this->idleTimeout == 0) {
                continue;
            }

            if ((time() - $data['lastAccessTime']) < $this->idleTimeout) {
                continue;
            }

            $data['conn']->close();
            unset($this->redis[$hostName]);
        }
    }

}