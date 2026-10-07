<?php
declare(strict_types=1);

/**
 * Needs a disposable Redis; skipped unless REDIS_TEST_PORT is set:
 *
 *   docker run -d --rm --name winter-modules-test-redis -p 127.0.0.1:16379:6379 redis:7.4-alpine
 *   REDIS_TEST_PORT=16379 php winter-data-redis/srcTest/RedisIntegrationTest.php
 *
 * The tests FLUSHALL that Redis.
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\cache\CacheConfiguration;
use dev\winterframework\cache\impl\SimpleValueWrapper;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\data\redis\cache\RedisCache;
use dev\winterframework\data\redis\consumer\ConsumerConfiguration;
use dev\winterframework\data\redis\exception\RedisQueueException;
use dev\winterframework\data\redis\phpredis\PhpRedisArrayTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisSentinelTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTemplate;
use dev\winterframework\data\redis\phpredis\PhpRedisTokenTemplate;
use dev\winterframework\data\redis\RedisQueueServiceImpl;
use dev\winterframework\data\redis\session\RedisSessionStore;
use dev\winterframework\util\SerializationUtil;

$port = intval(getenv('REDIS_TEST_PORT') ?: 0);
if ($port <= 0) {
    echo "RedisIntegrationTest skipped (set REDIS_TEST_PORT)\n";
    exit(0);
}

final class CachedDto {
    public function __construct(public string $v = '') {
    }
}

$single = new PhpRedisTemplate(['name' => 'it', 'host' => '127.0.0.1', 'port' => $port]);
$single->flushAll(false);

echo "RedisIntegrationTest\n";

T::test('single template: basic commands', function () use ($single) {
    T::true($single->set('k1', 'v1'));
    T::eq('v1', $single->get('k1'));
});

T::test('single template: concurrent hooked coroutines never mix replies', function () use ($single) {
    $errors = [];
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Co\run(function () use ($single, &$errors) {
        $wg = new Swoole\Coroutine\WaitGroup();
        for ($i = 0; $i < 50; $i++) {
            $wg->add();
            go(function () use ($single, $i, &$errors, $wg) {
                try {
                    for ($j = 0; $j < 20; $j++) {
                        $single->set("c:$i", "v$i-$j");
                        $got = $single->get("c:$i");
                        if ($got !== "v$i-$j") {
                            $errors[] = "c:$i expected v$i-$j got " . var_export($got, true);
                        }
                    }
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                } finally {
                    $wg->done();
                }
            });
        }
        $wg->wait();
    });
    Swoole\Runtime::enableCoroutine(0);
    T::eq([], array_slice($errors, 0, 3));
});

T::test('array template: constructs and answers (was uninitialized property)', function () use ($port) {
    $tpl = new PhpRedisArrayTemplate(['name' => 'arr', 'hosts' => ["127.0.0.1:$port"],
        'options' => [['lazy_connect' => true]]]);
    T::true($tpl->set('arr:k', 'x') === true);
    T::eq('x', $tpl->get('arr:k'));
});

T::test('sentinel template: constructor works on this phpredis', function () use ($port) {
    // A plain Redis answers PING like a sentinel does.
    $tpl = new PhpRedisSentinelTemplate(['name' => 'sent', 'host' => '127.0.0.1', 'port' => $port]);
    T::true($tpl->ping() !== false);
});

T::test('token template: routes, wraps past the highest token', function () use ($port) {
    $tpl = new PhpRedisTokenTemplate(['name' => 'tok', 'hosts' => [
        ['host' => '127.0.0.1', 'port' => $port, 'token' => 100],
        ['host' => 'localhost', 'port' => $port, 'token' => 200],
    ], 'strictTokenRing' => true]);
    $m = new ReflectionMethod($tpl, 'hostsFor');
    T::eq(['127.0.0.1'], $m->invoke($tpl, 50));
    T::eq(['localhost'], $m->invoke($tpl, 150));
    T::eq(['127.0.0.1'], $m->invoke($tpl, 999), 'hash past the last token wraps to the first');
    T::true($tpl->set('tok:k', 'y'));
    T::eq('y', $tpl->get('tok:k'));
});

T::test('cache: miss is NULL_VALUE, hit unserializes', function () use ($single) {
    $cache = new RedisCache($single, 'c1');
    T::true($cache->get('absent') === SimpleValueWrapper::$NULL_VALUE);
    $cache->put('dto', new CachedDto('a'));
    $v = $cache->get('dto')->get();
    T::true($v instanceof CachedDto && $v->v === 'a');
    $cache->put('f', false);
    T::true($cache->get('f') !== SimpleValueWrapper::$NULL_VALUE, 'cached false is a hit');
    T::eq(false, $cache->get('f')->get());
});

T::test('cache: getOrProvide calls the provider once', function () use ($single) {
    $cache = new RedisCache($single, 'c2');
    $calls = 0;
    $p = function () use (&$calls) {
        $calls++;
        return 'computed';
    };
    T::eq('computed', $cache->getOrProvide('k', $p)->get());
    T::eq('computed', $cache->getOrProvide('k', $p)->get());
    T::eq(1, $calls);
});

T::test('cache: putIfAbsent keeps the existing value (no TTL configured)', function () use ($single) {
    $cache = new RedisCache($single, 'c3');
    T::eq('first', $cache->putIfAbsent('k', 'first')->get());
    T::eq('first', $cache->putIfAbsent('k', 'second')->get());
    T::eq('first', $cache->get('k')->get());
});

T::test('cache: TTL, evict and clear', function () use ($single) {
    $cache = new RedisCache($single, 'c4', new CacheConfiguration(expireAfterWriteMs: 5000));
    $cache->put('a', 1);
    $cache->put('b', 2);
    T::true($single->ttl('winter.cache.c4.key.a') > 0, 'TTL applied');
    T::eq(true, $cache->evict('a'));
    T::eq(false, $cache->evict('a'));
    $cache->clear();
    T::eq(false, $cache->has('b'));
    $cache->clear(); // empty index must not error
});

T::test('cache: honours winter.security.unserialize.allowedClasses', function () use ($single) {
    $cache = new RedisCache($single, 'c5');
    $cache->put('dto', new CachedDto('x'));
    SerializationUtil::setAllowedClasses([]);
    try {
        T::true($cache->get('dto')->get() instanceof __PHP_Incomplete_Class);
    } finally {
        SerializationUtil::setAllowedClasses(true);
    }
});

T::test('session store: atomic write keeps username and sets TTL', function () use ($single) {
    $store = new RedisSessionStore($single, 'it:sess:', 60);
    $store->writeWithIdentity('s1', 'data1', 'alice', 2);
    $store->writeWithIdentity('s1', 'data2', '', 2);
    T::eq(['data' => 'data2', 'username' => 'alice', 'sessionType' => 2], $store->readWithIdentity('s1'));
    $ttl = $single->ttl('it:sess:s1');
    T::true($ttl > 0 && $ttl <= 60, 'ttl set');
    $store->destroy('s1');
    T::eq('', $store->read('s1'));
});

T::test('queue: entries sent before the group exists are delivered', function () use ($single) {
    $ctx = T::stub(ApplicationContext::class);
    $cfg = new ConsumerConfiguration(['name' => 'q1', 'workerClass' => 'X'], $ctx);
    (new ReflectionProperty($cfg, 'redisTpl'))->setValue($cfg, $single);

    $svc = new RedisQueueServiceImpl();
    $svc->addConsumer($cfg);
    $id = $svc->send('q1', ['n' => 1], ['payload' => 'spoofed', 'tenant' => 7]);
    T::true($id !== '');

    $cfg->ensureConsumerGroup();
    $cfg->ensureConsumerGroup(); // BUSYGROUP is ignored
    $res = $single->xreadgroup($cfg->getGroup(), 'c1', [$cfg->getStream() => '>'], 10, 100);
    $entries = $res[$cfg->getStream()] ?? [];
    T::eq(1, count($entries));
    $fields = reset($entries);
    T::eq('{"n":1}', $fields['payload'], 'reserved payload field cannot be overridden');
    T::eq('7', $fields['tenant']);
});

T::test('queue: trimStream passes threshold as string (phpredis 6)', function () use ($single) {
    $ctx = T::stub(ApplicationContext::class);
    $cfg = new ConsumerConfiguration(['name' => 'q2', 'workerClass' => 'X'], $ctx);
    (new ReflectionProperty($cfg, 'redisTpl'))->setValue($cfg, $single);

    $svc = new RedisQueueServiceImpl();
    $svc->addConsumer($cfg);
    for ($i = 0; $i < 5; $i++) {
        $svc->send('q2', ['n' => $i]);
    }
    T::eq(5, $svc->queueLength('q2'));
    T::true($svc->trimStream('q2', 0) >= 0);
});

T::test('queue: send failure throws instead of returning an empty id', function () use ($single) {
    $svc = new RedisQueueServiceImpl();
    $ctx = T::stub(ApplicationContext::class, [
        'hasBeanByName' => fn() => true,
        'beanByName' => fn() => $single,
    ]);
    (new ReflectionProperty($svc, 'appCtx'))->setValue($svc, $ctx);
    $single->set('notastream', 'x');
    T::throws(Throwable::class, fn() => $svc->send('notastream', 'm'));
});

$single->flushAll(false);
T::done();
