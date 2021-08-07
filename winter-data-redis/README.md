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

