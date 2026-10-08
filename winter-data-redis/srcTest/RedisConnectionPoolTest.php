<?php
declare(strict_types=1);

/**
 * php winter-data-redis/srcTest/RedisConnectionPoolTest.php
 *
 * Pool behaviour with fake connections (no Redis server needed).
 */

require __DIR__ . '/../../srcTestBootstrap.php';

use dev\winterframework\data\redis\phpredis\RedisConnectionPool;

final class FakeConn {
    public bool $closed = false;

    public function __construct(public string $id) {
    }

    public function close(): void {
        $this->closed = true;
    }
}

function newPool(array &$opened, int $idleTimeout = 0, int $maxIdle = 8): RedisConnectionPool {
    return new RedisConnectionPool(
        function (string $pid) use (&$opened): FakeConn {
            $c = new FakeConn($pid);
            $opened[] = $c;
            return $c;
        },
        'test',
        $idleTimeout,
        50,
        $maxIdle
    );
}

/** Wait until the wall clock has moved on by $secs (sleep() can return early on signals). */
function waitClock(int $secs): void {
    $until = time() + $secs;
    while (time() < $until) {
        usleep(50000);
    }
}

echo "RedisConnectionPoolTest\n";

T::test('outside coroutines one process-wide connection is reused', function () {
    $opened = [];
    $pool = newPool($opened);
    T::true($pool->get() === $pool->get());
    T::eq(1, count($opened));
});

T::test('invalidate closes the connection and the next get opens a new one', function () {
    $opened = [];
    $pool = newPool($opened);
    $a = $pool->get();
    $pool->invalidate($a);
    T::true($a->closed, 'invalidated connection closed');
    $b = $pool->get();
    T::true($a !== $b);
    T::eq(2, count($opened));
});

T::test('connections inherited through fork are abandoned, not closed', function () {
    $opened = [];
    $pool = newPool($opened);
    $parent = $pool->get();

    // Simulate running in a forked child.
    $ref = new ReflectionProperty($pool, 'pid');
    $ref->setValue($pool, getmypid() + 100000);

    $child = $pool->get();
    T::true($parent !== $child, 'child gets its own connection');
    T::true(!$parent->closed, 'parent socket must not be closed by the child');
});

T::test('pconnect ids are unique per live connection and reused after close', function () {
    $opened = [];
    $pool = newPool($opened, 0, 0);
    Co\run(function () use ($pool) {
        $wg = new Swoole\Coroutine\WaitGroup();
        for ($i = 0; $i < 3; $i++) {
            $wg->add();
            go(function () use ($pool, $wg) {
                $pool->get();
                Co::sleep(0.01);
                $wg->done();
            });
        }
        $wg->wait();
    });
    $ids = array_map(fn(FakeConn $c) => $c->id, $opened);
    T::eq(3, count(array_unique($ids)), 'concurrent connections need distinct persistent ids');

    Co\run(function () use ($pool) {
        $pool->get();
    });
    T::eq(4, count($opened));
    T::true(in_array(end($opened)->id, array_slice($ids, 0, 3), true), 'a freed id slot is reused');
});

T::test('concurrent coroutines never share a connection; ended ones are reused', function () {
    $opened = [];
    $pool = newPool($opened);
    $seen = [];
    Co\run(function () use ($pool, &$seen) {
        $wg = new Swoole\Coroutine\WaitGroup();
        for ($i = 0; $i < 5; $i++) {
            $wg->add();
            go(function () use ($pool, &$seen, $wg) {
                $c = $pool->get();
                T::true($pool->get() === $c, 'same coroutine, same connection');
                Co::sleep(0.01);
                $seen[] = spl_object_id($c);
                $wg->done();
            });
        }
        $wg->wait();
    });
    T::eq(5, count(array_unique($seen)), 'each live coroutine had its own connection');
    T::eq(5, $pool->getIdleCount(), 'ended coroutines return connections to the idle list');

    Co\run(function () use ($pool) {
        $pool->get();
    });
    T::eq(5, count($opened), 'next coroutine reuses an idle connection');
});

T::test('closeIdle closes only expired idle connections', function () {
    $opened = [];
    $pool = newPool($opened, 1);
    Co\run(function () use ($pool) {
        $pool->get();
    });
    T::eq(1, $pool->getIdleCount());
    waitClock(1);
    $pool->closeIdle();
    T::eq(0, $pool->getIdleCount());
    T::true($opened[0]->closed);
});

T::test('a long-lived connection idle past idleTimeout is replaced on next use', function () {
    $opened = [];
    $pool = newPool($opened, 1);
    $a = $pool->get();
    waitClock(1);
    $b = $pool->get();
    T::true($a !== $b, 'expired connection replaced');
    T::true($a->closed, 'expired connection closed');
});

T::test('closeIdle twice is harmless (no null connection access)', function () {
    $opened = [];
    $pool = newPool($opened, 1);
    $pool->closeIdle();
    $pool->closeIdle();
    T::eq(0, count($opened));
});

T::done();
