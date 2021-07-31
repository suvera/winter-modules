# WinterBoot Module - Memcache

Winter Data Memcache is a module that provides easy configuration and access to Memcache from Winter Boot applications.

## Setup

```shell
composer require suvera/winter-modules
```

Append following code to your application.yml

```yaml

modules:
    - module: 'dev\\winterframework\\data\\memcache\\MemcacheModule'
      enabled: true
      configFile: memcache-config.yml

```


## Implementation

PHP has two very good libraries that can used to work with Memcache.

1. [PhpMemcache](https://github.com/phpmemcache/phpmemcache) - C Extension
2. [Pmemcache](https://github.com/pmemcache/pmemcache)  - Pure PHP Implementation


We don't want to reinvent the wheel to create another library or even wrappers.

So, this module assumes that you have [PhpMemcache](https://github.com/phpmemcache/phpmemcache) extension already installed.


## Autowired Services

This module provides following services automatically available to you.

### 1. PhpMemcacheTemplate

When dealing with single memcache node(s)

`NOTE: if you are interested to use *Pmemcache* , please work on *PmemcacheTemplate* contributions are welcomed`



#### Configuration for PhpMemcacheTemplate (memcache-config.yml)

```yaml
phpmemcache:
    singles:
        -   name: memcacheNode01Bean
            host: 192.168.1.10
            port: 6379

        -   name: memcacheNode02Bean
            host: 192.168.1.12
            port: 6379

```


#### PHP Example

```phpt
#[Autowired]
private PhpMemcacheTemplate $memcache;  // This default to "memcacheNode01Bean"

#[Autowired("memcacheNode01Bean")]
private PhpMemcacheTemplate $memcache1;  // this is also same object as above, but with named Autowired


#[Autowired("memcacheNode02Bean")]
private PhpMemcacheTemplate $memcache2;

```


### 2. PhpMemcacheClusterTemplate

When dealing with Memcache Cluster [Memcache Cluster](https://github.com/phpmemcache/phpmemcache/blob/develop/cluster.markdown#readme)

`NOTE: if you are interested to use *Pmemcache* , please work on *PmemcacheClusterTemplate* contributions are welcomed`



#### Configuration for PhpMemcacheClusterTemplate (memcache-config.yml)

```yaml
phpmemcache:
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
private PhpMemcacheClusterTemplate $memcache;  // This default to "cluster01Bean"


#[Autowired("cluster01Bean")]
private PhpMemcacheClusterTemplate $memcache1;  // this is also same object as above, but with named Autowired


#[Autowired("cluster02Bean")]
private PhpMemcacheClusterTemplate $memcache2;

```



### 3. PhpMemcacheArrayTemplate

When dealing with Memcache Arrays [MemcacheArrays](https://github.com/phpmemcache/phpmemcache/blob/develop/arrays.markdown#readme)



#### Configuration for PhpMemcacheArrayTemplate (memcache-config.yml)

```yaml
phpmemcache:
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
private PhpMemcacheArrayTemplate $memcache;  // This default to "beanUniqueName01"

#[Autowired("beanUniqueName01")]
private PhpMemcacheArrayTemplate $memcache1;  // this is also same object as above, but with named Autowired

```


### 4. PhpMemcacheSentinelTemplate

When dealing with Memcache Sentinel [Memcache Sentinel](https://github.com/phpmemcache/phpmemcache/blob/develop/sentinel.markdown#readme)



#### Configuration for PhpMemcacheSentinelTemplate (memcache-config.yml)

```yaml
phpmemcache:
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
private PhpMemcacheSentinelTemplate $memcache;  // This default to "sentinel01"

```
