<?php
/** @noinspection PhpHierarchyChecksInspection */
declare(strict_types=1);

namespace dev\winterframework\data\memcache\mcd;

use Co;
use dev\winterframework\core\System;
use dev\winterframework\type\Arrays;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Memcached;
use Throwable;

class MemcachedTemplateImpl implements MemcachedTemplate {
    use Wlf4p;

    protected int $lastAccessTime = 0;
    protected int $lastIdleCheck = 0;
    protected int $idleTimeout = 0;
    protected ?Memcached $memcached = null;
    protected int $startTime;
    protected mixed $bootUpTimeMs;

    public function __construct(private array $config, private bool $lazy = false) {
        $this->startTime = System::currentTimeMillis();
        $this->bootUpTimeMs = $this->config['bootUpTimeMs'] ?? 0;

        $this->idleTimeout = $this->config['idleTimeout'] ?? 0;
        Arrays::assertKey($this->config, 'servers', 'Invalid Memcached config');
        TypeAssert::array($this->config['servers'], 'servers config value must be array in Memcached config');

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

        $this->memcached = new Memcached();
        $servers = $this->config['servers'];

        foreach ($servers as $server) {
            Arrays::assertKey($server, 'host', 'Invalid Memcached config');
            Arrays::assertKey($server, 'port', 'Invalid Memcached config');

            $this->memcached->addServer(
                $server['host'],
                intval($server['port']),
                $server['weight'] ?? 0
            );
        }

        if (isset($this->config['binaryProtocol'])) {
            $this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, boolval($this->config['binaryProtocol']));
        }
    }

    /**
     * @throws
     */
    public function __call(string $name, array $arguments): mixed {
        $this->lastAccessTime = time();

        $memcache = $this->memcached;
        if (is_null($memcache)) {
            $this->reConnect();
            $memcache = $this->memcached;
        } else {
            try {
                return $memcache->$name(...$arguments);
            } catch (Throwable $e) {
                self::logDebug($e->getMessage());
                $this->reconnect();
                $memcache = $this->memcached;
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
        $this->memcached->quit();
        $this->memcached = null;
    }
}