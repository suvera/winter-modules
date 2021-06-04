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

- [Apache Kafka](winter-kafka/README.md)
- Redis
- Cassandra 
- Elastic Search 
- MongoDB
- LDAP
- Security
- SOAP Web Services