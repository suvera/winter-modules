# AGENTS.md

Monorepo of Winter Boot modules (PHP >= 8.5, `declare(strict_types=1)` everywhere).
Core framework (DI, stereotypes, `WinterModule`, `ModuleTrait`) lives in the
external `suvera/winter-boot` dependency, not in this repo.

## Layout

- One module per directory: `winter-kafka/`, `winter-sqs/`, `winter-s3/`,
  `winter-data-redis/`, `winter-data-memcache/`, `winter-opensearch/`,
  `winter-dtce/`, `winter-security/`.
- Each module: `src/` (PSR-4, see root `composer.json`), `config/<name>-config.yml`,
  `README.md`, `srcTest/` (currently placeholder `keepme` files only).
- Root `composer.json` is the single autoload surface; there is no per-module
  `composer.json` and no phpunit config checked in.
- `winter-security` is a stub (`keepme.txt` only); root `composer.json` also
  references `winter-data-cassandra/mongo/elastic` namespaces with no matching
  directories — do not add code under those namespaces.

## Module pattern

Follow the existing shape (see `winter-s3/src/S3Module.php`,
`winter-sqs/src/SqsModule.php`):

- `*Module.php`: `#[Module]` attribute, `implements WinterModule`,
  `use ModuleTrait;`. `init()` stays empty; wire beans in `begin()` via
  `retrieveConfiguration()` + bean-provider registration.
- Registration in apps: `modules: [{module: <ModuleClass>, enabled: true,
  configFile: <name>-config.yml}]`; document the snippet in the module README.
- Bean names must be unique: throw `BeansException` on collision, never
  overwrite. Follow `<name>-template` style naming (e.g. `<s3Name>`,
  `<ds>-template`, redapplies to `PhpRedisTemplate`,
  `MemcacheTemplate`/`MemcachedTemplate`, `S3Template`, `OpenSearchTemplate`).
- Validate config arrays with `TypeAssert` (e.g. `TypeAssert::array($cfg, ...)`).
- Thin `*Template`/`*Service` wrappers around vendor clients; `*Util` statics
  only for client construction; `exception/` holds module exceptions.
- Consumers/workers: mirror `winter-kafka`/`winter-sqs`
  (`AbstractConsumer`, `ConsumerConfiguration(s)`, `*WorkerProcess` extending
  Swoole worker processes; DTCE uses Task Queue + Store + Worker with optional
  redis/kafka persistent backends).

## Winter Boot conventions (from `winter-boot` skill)

- DI: `#[Service]`/`#[Component]` on classes, `#[Configuration]` + `#[Bean]`
  factory methods, `#[Autowired]` / `#[Autowired("beanName")]` for injection,
  `#[Value('${path.to.prop}')]` for `application.yml` values.
- Async/scheduling needs Swoole: `#[EnableAsync]` / `#[EnableScheduling]` on
  app, `#[Async]`, `#[Scheduled(...)]`; daemon workers extend
  `ServerWorkerProcess` with a `\Co::sleep()` loop.
- Logging: `use dev\winterframework\util\log\Wlf4p;` then
  `self::logInfo()/logError()/logEx($e)` — no direct Monolog wiring in modules.
- Caching integrations expose a `CacheManager`-compatible bean where relevant
  (`RedisCache`, `MemcacheCache`). Distributed `#[Lockable]` locking: `RedisLockManager`
  in `winter-data-redis/src/lock/` (a `StoreLockManager` over `RedisLockStore`; needs
  winter-boot 2.1.6+). Other modules ship no `LockManager`.
- OpenSearch connections support `migrations: {enabled: ...}` with
  `*-template.json` / `*-policy.json` file conventions.

## Config

- Ship a sample `<name>-config.yml` under `<module>/config/` (see skill §7 for
  per-module keys: `phpredis.singles/clusters`, `kafka.consumers/producers`,
  `s3:[{name,...}]`, `sqs.connections`, `opensearch:[{name,hosts,...}]`).
- Keep credential/config key names consistent with siblings (reassemble
  flattened `credentials`/`http` blocks, cf. S3/SQS). Omit S3/SQS credentials
  to fall back to the IAM-role chain.

## Requires map

| Module | Needs |
|---|---|
| kafka | `ext-swoole` + `ext-rdkafka` |
| redis | `ext-swoole`, phpredis ext |
| memcache | `ext-memcache` or `ext-memcached` |
| sqs/s3 | `aws/aws-sdk-php` |
| opensearch | `opensearch-project/opensearch-php` |
| dtce | `ext-swoole` (redis/kafka optional) |

No new required `ext-*`/composer deps without updating `composer.json`
`require`/`suggest` and the module README.

## Build / verify

```shell
composer install
composer dump-autoload
php -l path/to/ChangedFile.php
```

No test runner is configured; `srcTest/` dirs are placeholders. If you add
behavior, add a matching `srcTest/` case and note how you ran it in your
summary (there is no `vendor/bin/phpunit` — do not claim `composer test`).

## Conventions

- PHP >= 8.5, 4-space indent, `strict_types=1`, typed properties/params/returns.
- Swoole-dependent paths (HTTP handlers, worker processes) must keep the
  existing curl-compatible fallback behavior where one exists.
- Keep changes scoped to one module per change unless the task says otherwise.
