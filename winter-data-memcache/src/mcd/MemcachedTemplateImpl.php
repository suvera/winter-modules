<?php
/** @noinspection PhpHierarchyChecksInspection */
declare(strict_types=1);

namespace dev\winterframework\data\memcache\mcd;

use dev\winterframework\core\System;
use dev\winterframework\data\memcache\util\BootUpDelay;
use dev\winterframework\data\memcache\util\MemcacheConnectionPool;
use dev\winterframework\type\Arrays;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Memcached;
use Throwable;

class MemcachedTemplateImpl implements MemcachedTemplate {
    use Wlf4p;

    protected MemcacheConnectionPool $pool;
    protected int $startTime;
    protected int $bootUpTimeMs;

    public function __construct(private array $config, private bool $lazy = false) {
        $this->startTime = System::currentTimeMillis();
        $this->bootUpTimeMs = intval($this->config['bootUpTimeMs'] ?? 0);

        Arrays::assertKey($this->config, 'servers', 'Invalid Memcached config');
        TypeAssert::array($this->config['servers'], 'servers config value must be array in Memcached config');

        $this->pool = MemcacheConnectionPool::fromConfig(
            fn(string $persistentId): Memcached => $this->connect(),
            'memcached-' . ($this->config['name'] ?? ''),
            $this->config,
            fn(Memcached $m) => $m->quit()
        );

        if (!$this->lazy) {
            $this->pool->get();
        }
    }

    protected function connect(): Memcached {
        if ($this->lazy) {
            BootUpDelay::await($this->startTime, $this->bootUpTimeMs);
        }

        $memcached = new Memcached();
        foreach ($this->config['servers'] as $server) {
            Arrays::assertKey($server, 'host', 'Invalid Memcached config');
            Arrays::assertKey($server, 'port', 'Invalid Memcached config');

            $memcached->addServer(
                $server['host'],
                intval($server['port']),
                intval($server['weight'] ?? 0)
            );
        }

        if (isset($this->config['binaryProtocol'])) {
            $memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, boolval($this->config['binaryProtocol']));
        }

        return $memcached;
    }

    /**
     * Runs once: Memcached reports network failures through return values,
     * and re-sending a write (increment, append, ...) could apply it twice.
     *
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $memcached = $this->pool->get();
        try {
            return $memcached->$name(...$arguments);
        } catch (Throwable $e) {
            $this->pool->invalidate($memcached);
            throw $e;
        }
    }

    public function checkIdleConnection(): void {
        $this->pool->closeIdle();
    }
}
