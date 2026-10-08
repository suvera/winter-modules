# WinterBoot Module - Redis

Winter Data Redis is a module that provides easy configuration and access to Redis from Winter Boot applications.

## Setup

```shell
composer require suvera/winter-modules
```

Append following code to your application.yml

```yaml

modules:
    - module: 'dev\winterframework\data\redis\RedisModule'
      enabled: true
      configFile: redis-config.yml

```


## Implementation

PHP has two very good libraries that can used to work with Redis.

1. [PhpRedis](https://github.com/phpredis/phpredis) - C Extension
2. [Predis](https://github.com/predis/predis)  - Pure PHP Implementation


We don't want to reinvent the wheel to create another library or even wrappers.

So, this module assumes that you have [PhpRedis](https://github.com/phpredis/phpredis) extension already installed.

### Connections, coroutines and forks

Every template keeps a small connection pool (built on winter-boot's
`CoroutineScopedPool`):

- each Swoole coroutine gets its own connection, so concurrent coroutines
  never share a socket (required once `server.swoole.hook_flags` enables
  `SWOOLE_HOOK_TCP`/`SWOOLE_HOOK_ALL`, and always in worker processes);
- a coroutine's connection returns to an idle list when the coroutine ends
  and is reused by the next one;
- connections opened before Swoole forks its workers are left to the parent
  and never shared with a child.

Optional keys on every `singles/clusters/arrays/sentinels/tokens` entry:

```yaml
            idleTimeout: 30        # seconds; idle connections are closed/replaced (0 = never)
            maxConnections: 50     # per template per worker process
            maxIdle: 8             # idle connections kept for reuse
            maxWaitMs: 5000        # wait for a free connection, then PoolExhaustedException
```

After a connection error, only read-only commands (GET, HGETALL, XRANGE, ...)
are re-sent on a new connection. A write whose reply was lost is never
re-sent automatically (it may already have been applied); the
`RedisException` reaches the caller. `<command>_xwait` calls (e.g.
`lPop_xwait`) keep retrying with backoff until Redis answers.


## Autowired Services

This module provides following services automatically available to you.

### 1. PhpRedisTemplate

When dealing with single redis node(s)

`NOTE: if you are interested to use *Predis* , please work on *PredisTemplate* contributions are welcomed`



#### Configuration for PhpRedisTemplate (redis-config.yml)

```yaml
phpredis:
    singles:
        -   name: redisNode01Bean
            host: 192.168.1.10
            port: 6379

        -   name: redisNode02Bean
            host: 192.168.1.12
            port: 6379

```


#### PHP Example

```phpt
#[Autowired]
private PhpRedisTemplate $redis;  // This default to "redisNode01Bean"

#[Autowired("redisNode01Bean")]
private PhpRedisTemplate $redis1;  // this is also same object as above, but with named Autowired


#[Autowired("redisNode02Bean")]
private PhpRedisTemplate $redis2;

```


### 2. PhpRedisClusterTemplate

When dealing with Redis Cluster [Redis Cluster](https://github.com/phpredis/phpredis/blob/develop/cluster.markdown#readme)

`NOTE: if you are interested to use *Predis* , please work on *PredisClusterTemplate* contributions are welcomed`



#### Configuration for PhpRedisClusterTemplate (redis-config.yml)

```yaml
phpredis:
    clusters:
        -   name: cluster01Bean
            clusterName: cluster01
            hosts: [ 192.168.1.10:6379, 192.168.1.11:6379, 192.168.1.12:6379]
        
        -   name: cluster02Bean
            clusterName: cluster02
            hosts: [ 192.168.1.14:6379, 192.168.1.15:6379]
```


#### PHP Example

```phpt
#[Autowired]
private PhpRedisClusterTemplate $redis;  // This default to "cluster01Bean"


#[Autowired("cluster01Bean")]
private PhpRedisClusterTemplate $redis1;  // this is also same object as above, but with named Autowired


#[Autowired("cluster02Bean")]
private PhpRedisClusterTemplate $redis2;

```



### 3. PhpRedisArrayTemplate

When dealing with Redis Arrays [RedisArrays](https://github.com/phpredis/phpredis/blob/develop/arrays.markdown#readme)



#### Configuration for PhpRedisArrayTemplate (redis-config.yml)

```yaml
phpredis:
    arrays:
        -   name: beanUniqueName01
            arrayName: arrayName01
            hosts: [192.168.1.10:6379, 192.168.1.11:6379, 192.168.1.12:6379],
            options:
                -   consistent: true
                    auth: mysecretpassword
                    function: extract_key_part_func
                    previous: [ ]
                    retry_timeout: 0
                    lazy_connect: false
                    connect_timeout: 0.5
                    read_timeout: 0.5
                    algorithm: sha256
                    distributor: dist_func
```


#### PHP Example

```phpt
#[Autowired]
private PhpRedisArrayTemplate $redis;  // This default to "beanUniqueName01"

#[Autowired("beanUniqueName01")]
private PhpRedisArrayTemplate $redis1;  // this is also same object as above, but with named Autowired

```


### 4. PhpRedisSentinelTemplate

When dealing with Redis Sentinel [Redis Sentinel](https://github.com/phpredis/phpredis/blob/develop/sentinel.markdown#readme)



#### Configuration for PhpRedisSentinelTemplate (redis-config.yml)

```yaml
phpredis:
    sentinels:
        -   name: sentinel01
            persistence: true
            host: 127.0.0.1
            port: 6379
            timeout: 0
            readTimeout: 0
```


#### PHP Example

```phpt
#[Autowired]
private PhpRedisSentinelTemplate $redis;  // This default to "sentinel01"
```

### 5. PhpRedisTokenTemplate

When dealing with Token based ring topology based Redis Cluster such as [Netflix Dynomite](https://github.com/Netflix/dynomite)

#### Configuration for PhpRedisTokenTemplate (redis-config.yml)

```yaml
phpredis:
    tokens:
        # 1st Cluster
        -   name: DynomiteCluster01
            hosts:
                -   host: 10.1.2.3
                    port: 7801
                    token: 4294967295
                -   host: 10.1.2.4
                    port: 7801
                    token: 2863311530
                -   host: 10.1.2.5
                    port: 7801
                    token: 1431655765
            persistence: false
            strictTokenRing: false
            timeout: 0
            retryInterval:
            reserved:
            readTimeout: 0
            idleTimeout: 300
            hashProvider: dev\winterframework\util\hash\MurmurHash3Provider
            
        # 2nd Cluster
        -   name: DynomiteCluster02
            # ...settings here ...

```


#### PHP Example

```phpt
#[Autowired("DynomiteCluster01")]
private PhpRedisTokenTemplate $redis;
```

### 6. RedisSessionStore

Session storage for Winter Boot request sessions (`dev\winterframework\web\session\SessionManager` in `suvera/winter-boot`). Keeps each session as a hash under `keyPrefix + sessionId` (fields `data`/`username`/`type`) with a native Redis TTL, so expiry needs no sweeping. Takes any template above — single, array, sentinel, cluster or token — since all expose the same hash commands. Implements the session identity contract: `username`/`type` set at login round-trip on every later request, and a save carrying no name keeps the stored one.

#### PHP Example

```phpt
use dev\winterframework\data\redis\phpredis\PhpRedisTemplate;
use dev\winterframework\data\redis\session\RedisSessionStore;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\stereotype\Bean;
use dev\winterframework\stereotype\Configuration;

#[Configuration]
class SessionConfig
{
    #[Autowired]
    protected PhpRedisTemplate $redis;  // or #[Autowired("redisNode02Bean")] with several

    #[Bean]
    public function sessionStore(): \SessionHandlerInterface
    {
        return new RedisSessionStore($this->redis, keyPrefix: 'myapp:sess:', ttlSecs: 3600);
    }
}
```

With `ttlSecs <= 0` keys persist until explicitly destroyed. The declared `#[Bean]` return type must be `\SessionHandlerInterface` — that is what replaces the framework's default file store.

### 7. Reliable Queue Consumers (Redis Streams)

Stream-backed reliable queue with auto-started consumers, mirroring the
Kafka/SQS modules (Java equivalent: Redisson PRO Reliable Queue).
At-least-once delivery per consumer group: entries are `XACK`ed only after
your worker consumes them; crashed-worker leftovers stay pending and are
reclaimed via `XCLAIM` after `claimIdleMs`; entries redelivered past
`maxDeliveries` (or failing permanently) move to `deadLetterStream`.

#### Configuration for consumers (redis-config.yml)

```yaml
redis:
    consumers:
        -   name: order-events-consumer
            redis: redisNode01Bean   # omit to use the default redis bean
            stream: order-events
            group: order-events-group
            workerNum: 2
            workerClass: App\Queue\OrderEventsConsumer
            deadLetterStream: order-events-dlq
            blockMs: 5000
            batchSize: 10
            claimIdleMs: 30000
            maxDeliveries: 5
            retries: 3
            retryWaitMs: 300
            transientExceptions: []
```

`stream` defaults to the consumer `name`, `group` to `<stream>-group`.
Consumers start automatically as Swoole worker processes
(`workerNum` processes per consumer).

The group is created at stream id `0` (with `MKSTREAM`), so entries sent
before the first worker started are delivered too. If Redis is unreachable
when a worker starts, it keeps retrying group creation instead of exiting
(an exiting worker process stops the whole server).

`RedisQueueService::send()` throws `RedisQueueException` when Redis does not
accept the entry. Extra `$fields` are stored next to the reserved `payload`
and `createdAt` fields and cannot override them.

#### PHP Example

```phpt
use dev\winterframework\data\redis\consumer\AbstractConsumer;
use dev\winterframework\data\redis\consumer\ConsumerRecords;
use dev\winterframework\data\redis\RedisQueueService;

class OrderEventsConsumer extends AbstractConsumer
{
    public function consume(ConsumerRecords $records): void
    {
        foreach ($records as $record) {
            $payload = $record->getPayload(); // "payload" stream field
            // ... process; throw on transient failure to retry/redeliver
        }
    }
}

#[Autowired]
private RedisQueueService $queues;

$this->queues->send('order-events-consumer', ['orderId' => 42]);
$this->queues->send('order-events', 'raw-stream-name-also-works');
```

Only transient exceptions listed in `transientExceptions` are retried
(`retries` in-worker attempts, then redelivery via the pending list).
Any other exception is permanent: the message goes to `deadLetterStream`
(or is dropped with an error log when none is configured) so one poison
message never blocks the stream.

