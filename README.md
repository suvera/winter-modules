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

- [Cassandra](winter-data-cassandra/README.md) 
- [Elastic Search](winter-data-elastic/README.md) 
- [MongoDB](winter-data-mongo/README.md) 
- [Redis](winter-data-redis/README.md) 
- [Apache Kafka](winter-kafka/README.md) 
- [LDAP](winter-ldap/README.md)
- [Security](winter-security/README.md)
- [SOAP Web Services](winter-soap/README.md)