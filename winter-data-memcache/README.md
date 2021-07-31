# WinterBoot Module - Memcache

Winter Data Memcache is a module that provides easy configuration and access to Memcached service from Winter Boot applications.

## Setup

```shell
composer require suvera/winter-modules
```

Append following code to your application.yml

```yaml

modules:
    - module: 'dev\winterframework\data\memcache\MemcacheModule'
      enabled: true
      configFile: memcache-config.yml

```

## Implementation

PHP has two very good libraries that can used to work with Memcache.

1. [Memcached](https://www.php.net/manual/en/book.memcached.php)
2. [Memcache](https://www.php.net/manual/en/book.memcache.php)

One of these extension is required.

```shell
pecl install memcached

pecl install memcache
```


## Autowired Services

This module provides following services automatically available to you.

### 1. MemcachedTemplate

When **memcached** php extension installed.


### 2. MemcacheTemplate

When **memcache** php extension installed.


#### Configuration (memcache-config.yml)

```yaml
# When **memcache** php extension installed.
memcache:
    -   name: bean01
        idleTimeout: 30
        timeout: 0
        retry_interval: 0
        status: 0
        servers:
            -   host: 127.0.0.1
                port: 11211
                weight: 0

# When **memcached** php extension installed.
memcached:
    -   name: bean02
        timeout: 0
        idleTimeout: 30
        retry_interval: 0
        status: 0
        servers:
            -   host: 127.0.0.1
                port: 11211
                weight: 0
```

#### PHP Example

```phpt
#[Autowired("bean02")]
private MemcachedTemplate $memcache1;


#[Autowired("bean01")]
private MemcacheTemplate $memcache2;
```
