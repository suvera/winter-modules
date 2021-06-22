# Winter Boot Modules

Winter boot [https://github.com/suvera/winter-boot](https://github.com/suvera/winter-boot) eco system and functional Dependencies


## Setup

Example setup (append in application.yml )

```yaml

modules:
    - module: 'dev\\winterframework\\data\\redis\\RedisModule'
      enabled: true
      
    - module: 'dev\\winterframework\\data\\abc\\AbcModule'
      enabled: true

```

## Modules

- [Apache Kafka](winter-kafka/)
- [Redis](winter-data-redis/)
- [Distributed Task Computing Engine](winter-dtce/)
- Cassandra 
- Elastic Search 
- MongoDB
- Security