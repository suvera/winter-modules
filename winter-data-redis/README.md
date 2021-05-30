# Winter Boot Module - Redis

Winter Data Redis is a module that provides easy configuration and access to Redis from Winter Boot applications.


- Connection package as low-level abstraction across multiple Redis drivers(phpredis and predis).
- RedisTemplate


## Setup

append following code to your application.yml

```yaml

modules:
    - module: 'dev\\winterframework\\data\\redis\\RedisModule'
      enabled: true

```