<?php
/** @noinspection PhpHierarchyChecksInspection */
declare(strict_types=1);

namespace dev\winterframework\data\memcache\mc;

use dev\winterframework\core\System;
use dev\winterframework\data\memcache\util\BootUpDelay;
use dev\winterframework\data\memcache\util\MemcacheConnectionPool;
use dev\winterframework\type\Arrays;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Memcache;
use Throwable;

class MemcacheTemplateImpl implements MemcacheTemplate {
    use Wlf4p;

    protected MemcacheConnectionPool $pool;
    protected int $startTime;
    protected int $bootUpTimeMs;

    public function __construct(private array $config, private bool $lazy = false) {
        $this->startTime = System::currentTimeMillis();
        $this->bootUpTimeMs = intval($this->config['bootUpTimeMs'] ?? 0);

        Arrays::assertKey($this->config, 'servers', 'Invalid Memcache config');
        TypeAssert::array($this->config['servers'], 'servers config value must be array in Memcache config');

        // ext-memcache talks through PHP streams, which Swoole hooks: each
        // coroutine needs its own connection (see MemcacheConnectionPool).
        $this->pool = MemcacheConnectionPool::fromConfig(
            fn(string $persistentId): Memcache => $this->connect(),
            'memcache-' . ($this->config['name'] ?? ''),
            $this->config,
            fn(Memcache $m) => $m->close()
        );

        if (!$this->lazy) {
            $this->pool->get();
        }
    }

    protected function connect(): Memcache {
        if ($this->lazy) {
            BootUpDelay::await($this->startTime, $this->bootUpTimeMs);
        }

        $memcache = new Memcache();
        foreach ($this->config['servers'] as $server) {
            Arrays::assertKey($server, 'host', 'Invalid Memcache config');
            Arrays::assertKey($server, 'port', 'Invalid Memcache config');

            $memcache->addServer(
                $server['host'],
                intval($server['port']),
                false,
                intval($server['weight'] ?? 0),
                intval($this->config['timeout'] ?? 1),
                intval($this->config['retry_interval'] ?? -1),
                !empty($this->config['status'])
            );
        }

        return $memcache;
    }

    /**
     * Runs once: Memcache reports network failures through return values,
     * and re-sending a write (increment, append, ...) could apply it twice.
     *
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $memcache = $this->pool->get();
        try {
            return $memcache->$name(...$arguments);
        } catch (Throwable $e) {
            $this->pool->invalidate($memcache);
            throw $e;
        }
    }

    public function checkIdleConnection(): void {
        $this->pool->closeIdle();
    }
}
