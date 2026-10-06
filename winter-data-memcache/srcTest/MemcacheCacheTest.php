<?php
declare(strict_types=1);

/**
 * php winter-data-memcache/srcTest/MemcacheCacheTest.php
 *
 * MemcacheCache against an in-memory MemcachedTemplate fake with
 * Memcached's return conventions (false on miss).
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\cache\impl\SimpleValueWrapper;
use dev\winterframework\data\memcache\cache\MemcacheCache;
use dev\winterframework\data\memcache\mcd\MemcachedTemplate;
use dev\winterframework\data\memcache\util\BootUpDelay;
use dev\winterframework\util\SerializationUtil;

final class FakeMemcached implements MemcachedTemplate {
    public array $data = [];

    public function __call(string $name, array $args): mixed {
        $k = $args[0] ?? null;
        return match ($name) {
            'get' => $this->data[$k] ?? false,
            'set' => (bool)($this->data[$k] = $args[1]),
            'add' => isset($this->data[$k]) ? false : (bool)($this->data[$k] = $args[1]),
            'delete' => (function () use ($k) {
                $had = isset($this->data[$k]);
                unset($this->data[$k]);
                return $had;
            })(),
            'increment' => isset($this->data[$k]) ? ($this->data[$k] = intval($this->data[$k]) + 1) : false,
            default => throw new LogicException($name),
        };
    }
}

final class CachedThing {
    public function __construct(public int $n = 0) {
    }
}

echo "MemcacheCacheTest\n";

T::test('miss returns NULL_VALUE, not a hit of false', function () {
    $cache = new MemcacheCache(new FakeMemcached(), 'c');
    T::true($cache->get('nope') === SimpleValueWrapper::$NULL_VALUE);
});

T::test('get returns the original value (unserialized)', function () {
    $cache = new MemcacheCache(new FakeMemcached(), 'c');
    $cache->put('o', new CachedThing(5));
    $v = $cache->get('o')->get();
    T::true($v instanceof CachedThing && $v->n === 5);
    $cache->put('f', false);
    T::true($cache->get('f') !== SimpleValueWrapper::$NULL_VALUE);
});

T::test('getOrProvide calls the provider once', function () {
    $cache = new MemcacheCache(new FakeMemcached(), 'c');
    $calls = 0;
    $p = function () use (&$calls) {
        $calls++;
        return [1, 2];
    };
    T::eq([1, 2], $cache->getOrProvide('k', $p)->get());
    T::eq([1, 2], $cache->getOrProvide('k', $p)->get());
    T::eq(1, $calls);
});

T::test('putIfAbsent only writes when absent', function () {
    $cache = new MemcacheCache(new FakeMemcached(), 'c');
    T::eq('a', $cache->putIfAbsent('k', 'a')->get());
    T::eq('a', $cache->putIfAbsent('k', 'b')->get());
    T::eq('a', $cache->get('k')->get());
});

T::test('clear only affects this cache', function () {
    $mc = new FakeMemcached();
    $mc->data['foreign-key'] = 'keep me';
    $a = new MemcacheCache($mc, 'a');
    $b = new MemcacheCache($mc, 'b');
    $a->put('k', 1);
    $b->put('k', 2);
    $a->clear();
    T::eq(false, $a->has('k'));
    T::eq(2, $b->get('k')->get());
    T::eq('keep me', $mc->data['foreign-key']);
});

T::test('evict', function () {
    $cache = new MemcacheCache(new FakeMemcached(), 'c');
    $cache->put('k', 1);
    T::eq(true, $cache->evict('k'));
    T::eq(false, $cache->has('k'));
});

T::test('over-long / whitespace keys are hashed into valid memcached keys', function () {
    $mc = new FakeMemcached();
    $cache = new MemcacheCache($mc, 'c');
    $cache->put(str_repeat('x', 400), 1);
    $cache->put('has space', 2);
    foreach (array_keys($mc->data) as $k) {
        T::true(strlen($k) <= 250 && !preg_match('/\s/', $k), "invalid key $k");
    }
    T::eq(2, $cache->get('has space')->get());
});

T::test('unserialize honours allowedClasses', function () {
    $cache = new MemcacheCache(new FakeMemcached(), 'c');
    $cache->put('o', new CachedThing(1));
    SerializationUtil::setAllowedClasses([]);
    try {
        T::true($cache->get('o')->get() instanceof __PHP_Incomplete_Class);
    } finally {
        SerializationUtil::setAllowedClasses(true);
    }
});

T::test('boot-up delay waits only for the remaining time', function () {
    $start = intval(microtime(true) * 1000) - 150;
    $t = microtime(true);
    BootUpDelay::await($start, 300);
    $waited = (microtime(true) - $t) * 1000;
    T::true($waited >= 100 && $waited < 250, "waited {$waited}ms, expected ~150ms");
    $t = microtime(true);
    BootUpDelay::await($start, 100);
    T::true((microtime(true) - $t) < 0.05, 'no wait once boot-up time passed');
});

T::done();
