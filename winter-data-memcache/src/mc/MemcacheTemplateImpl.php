<?php
/** @noinspection PhpHierarchyChecksInspection */
declare(strict_types=1);

namespace dev\winterframework\data\memcache\mc;

use Co;
use dev\winterframework\core\System;
use dev\winterframework\type\Arrays;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Memcache;
use Throwable;

class MemcacheTemplateImpl implements MemcacheTemplate {
    use Wlf4p;

    protected int $lastAccessTime = 0;
    protected int $lastIdleCheck = 0;
    protected int $idleTimeout = 0;
    protected ?Memcache $memcache = null;
    protected int $startTime;
    protected mixed $bootUpTimeMs;

    public function __construct(private array $config, private bool $lazy = false) {
        $this->startTime = System::currentTimeMillis();
        $this->bootUpTimeMs = $this->config['bootUpTimeMs'] ?? 0;

        $this->idleTimeout = $this->config['idleTimeout'] ?? 0;
        Arrays::assertKey($this->config, 'servers', 'Invalid Memcache config');
        TypeAssert::array($this->config['servers'], 'servers config value must be array in Memcache config');

        if (!$this->lazy) {
            $this->reconnect();
        }
    }

    private function reconnect(): void {
        if ($this->lazy && $this->bootUpTimeMs > 0 && (System::currentTimeMillis() - $this->startTime) < $this->bootUpTimeMs) {
            Co::sleep((System::currentTimeMillis() - $this->startTime) / 1000);
        }

        $this->lastAccessTime = time();
        $this->lastIdleCheck = time();

        $this->memcache = new Memcache();
        $servers = $this->config['servers'];

        foreach ($servers as $server) {
            Arrays::assertKey($server, 'host', 'Invalid Memcache config');
            Arrays::assertKey($server, 'port', 'Invalid Memcache config');

            $this->memcache->addServer(
                $server['host'],
                intval($server['port']),
                false,
                $server['weight'] ?? 0,
                $this->config['timeout'] ?? 1,
                $this->config['retry_interval'] ?? -1,
                isset($this->config['status']) && $this->config['status']
            );
        }
    }

    /**
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $this->lastAccessTime = time();

        $memcache = $this->memcache;
        if (is_null($memcache)) {
            $this->reConnect();
            $memcache = $this->memcache;
        } else {
            try {
                return $memcache->$name(...$arguments);
            } catch (Throwable $e) {
                self::logDebug($e->getMessage());
                $this->reconnect();
                $memcache = $this->memcache;
            }
        }

        return $memcache->$name(...$arguments);
    }

    public function checkIdleConnection(): void {
        if ($this->lastAccessTime == 0 || $this->idleTimeout == 0) {
            return;
        }

        if ((time() - $this->lastAccessTime) < $this->idleTimeout) {
            return;
        }

        $this->lastIdleCheck = time();
        $this->lastAccessTime = time();
        $this->memcache->close();
        $this->memcache = null;
    }
}