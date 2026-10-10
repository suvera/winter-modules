<?php
declare(strict_types=1);

/**
 * Needs a disposable Redis; skipped unless REDIS_TEST_PORT is set:
 *
 *   docker run -d --rm --name winter-modules-test-redis -p 127.0.0.1:16379:6379 redis:7.4-alpine
 *   REDIS_TEST_PORT=16379 php winter-data-redis/srcTest/RedisLockTest.php
 *
 * Needs a winter-boot with dev\winterframework\util\concurrent\StoreLockManager (2.1.6+).
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\data\redis\lock\RedisLockManager;
use dev\winterframework\data\redis\lock\RedisLockStore;
use dev\winterframework\data\redis\phpredis\PhpRedisTemplate;
use dev\winterframework\util\concurrent\LockException;

$port = intval(getenv('REDIS_TEST_PORT') ?: 0);
if ($port <= 0) {
    echo "RedisLockTest skipped (set REDIS_TEST_PORT)\n";
    exit(0);
}

/** Two templates stand in for two pods. */
$pod1 = new PhpRedisTemplate(['name' => 'lock1', 'host' => '127.0.0.1', 'port' => $port]);
$pod2 = new PhpRedisTemplate(['name' => 'lock2', 'host' => '127.0.0.1', 'port' => $port]);
$prefix = 'test:lock:' . bin2hex(random_bytes(3)) . ':';

echo "RedisLockTest\n";

T::test('locks exclude across pods and free on unlock', function () use ($pod1, $pod2, $prefix) {
    $a = (new RedisLockManager($pod1, $prefix, 5))->provideLock('order-7', 30);
    $b = (new RedisLockManager($pod2, $prefix, 5))->provideLock('order-7', 30);
    T::true($a->tryLock());
    T::true(!$b->tryLock(30), 'other pod must not get a held lock');
    T::true((new RedisLockManager($pod2, $prefix))->provideLock('order-8', 30)->tryLock(), 'other names are free');
    $a->unlock();
    T::true($b->tryLock(0));
    $b->unlock();
});

T::test('owner is checked and TTL expires', function () use ($pod1, $prefix) {
    $store = new RedisLockStore($pod1, $prefix);
    T::true($store->acquire('k', 'owner-a', 50));
    T::true(!$store->acquire('k', 'owner-b', 1000), 'held');
    T::true(!$store->release('k', 'owner-b'), 'not the owner');
    T::true(!$store->refresh('k', 'owner-b', 1000), 'not the owner');
    usleep(80000);
    T::true($store->acquire('k', 'owner-b', 60000), 'expired lease can be taken');
    T::true(!$store->release('k', 'owner-a'), 'old owner can no longer release');
    T::true($store->refresh('k', 'owner-b', 0), 'refresh to no expiry');
    T::eq(-1, $pod1->pttl($prefix . 'k'), 'no TTL after refresh(0)');
    T::true($store->release('k', 'owner-b'));
    T::eq(0, (int)$pod1->exists($prefix . 'k'));
});

T::test('a lost lease makes update() throw', function () use ($pod1, $prefix) {
    $mgr = new RedisLockManager($pod1, $prefix);
    $lock = $mgr->provideLock('lost', 1);
    T::true($lock->tryLock());
    $pod1->del($prefix . 'lost');          // e.g. the TTL ran out
    T::throws(LockException::class, fn() => $lock->update(5));
    T::true(!$lock->isLocked());
});

T::test('serializer and prefix on the connection do not break ownership', function () use ($port, $prefix) {
    $raw = new Redis();
    $raw->connect('127.0.0.1', $port);
    $raw->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
    $raw->setOption(Redis::OPT_PREFIX, 'app1:');
    $store = new RedisLockStore($raw, $prefix);
    T::true($store->acquire('s', 'owner-x', 5000));
    T::true(!$store->acquire('s', 'owner-y', 5000));
    T::true($store->refresh('s', 'owner-x', 5000));
    T::true($store->release('s', 'owner-x'));
});

T::test('coroutines of one worker exclude each other', function () use ($pod1, $prefix) {
    $mgr = new RedisLockManager($pod1, $prefix, 5);
    $inside = 0;
    $max = 0;
    $acquired = 0;
    Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
    Co\run(function () use ($mgr, &$inside, &$max, &$acquired) {
        for ($i = 0; $i < 5; $i++) {
            go(function () use ($mgr, &$inside, &$max, &$acquired) {
                $lock = $mgr->provideLock('co', 10);
                if (!$lock->tryLock(3000)) {
                    return;
                }
                $acquired++;
                $inside++;
                $max = max($max, $inside);
                Co::sleep(0.01);
                $inside--;
                $lock->unlock();
            });
        }
    });
    Swoole\Runtime::enableCoroutine(0);
    T::eq(5, $acquired);
    T::eq(1, $max, 'sections overlapped');
});

T::done();
