<?php
declare(strict_types=1);

namespace dev\winterframework\data\memcache\util;

use Closure;
use dev\winterframework\coroutine\CoroutineScopedPool;
use dev\winterframework\coroutine\CoroutineScopeProvider;
use dev\winterframework\coroutine\CoroutineScopeProviders;
use SplObjectStorage;
use Throwable;

/**
 * Connections of one Memcache template, safe across fork() and coroutines.
 *
 * - Every coroutine gets its own connection (winter-boot CoroutineScopedPool),
 *   so with Swoole runtime hooks on, concurrent coroutines never interleave
 *   commands/replies on one socket. Outside coroutines a single process-wide
 *   connection is used.
 * - When a coroutine ends its connection goes back to an idle list and is
 *   reused by the next coroutine, so requests do not pay a connect each.
 * - State inherited through fork() (Swoole forks workers after modules boot)
 *   is abandoned, never closed: the parent still owns those sockets. The
 *   child builds its own connections on first use.
 */
final class MemcacheConnectionPool {
    /** Inherited state stays referenced so destructors never touch parent sockets. */
    private static array $abandoned = [];

    private int $pid = 0;
    private ?CoroutineScopedPool $pool = null;
    /** @var object[] */
    private array $idle = [];
    /**
     * Per-connection bookkeeping (broken flag, last use, persistent-id slot).
     * SplObjectStorage, not WeakMap: a WeakMap written from coroutines
     * crashes PHP 8.5 + Swoole 6.2 at shutdown. Entries are detached
     * whenever a connection is closed.
     */
    private SplObjectStorage $broken;
    private SplObjectStorage $lastUsed;
    private SplObjectStorage $ids;
    /** @var int[] persistent-id slots free for reuse */
    private array $freeIds = [];
    private int $nextId = 0;

    /**
     * @param Closure(string): object $connect opens one connection; the argument
     *        is a persistent id unique within this process
     * @param Closure(object): void|null $close closes one connection
     */
    public function __construct(
        private Closure $connect,
        private string $name,
        private int $idleTimeout = 0,
        private int $maxConnections = 50,
        private int $maxIdle = 8,
        private int $maxWaitMs = 5000,
        private ?Closure $close = null,
        private ?CoroutineScopeProvider $scopes = null
    ) {
        $this->maxIdle = max(0, $this->maxIdle);
    }

    public static function fromConfig(Closure $connect, string $name, array $config, ?Closure $close = null): self {
        return new self(
            $connect,
            $name,
            intval($config['idleTimeout'] ?? 0),
            intval($config['maxConnections'] ?? 50),
            intval($config['maxIdle'] ?? 8),
            intval($config['maxWaitMs'] ?? 5000),
            $close
        );
    }

    /**
     * Connection owned by the calling coroutine (or the process outside coroutines).
     */
    public function get(): object {
        $this->checkFork();

        $conn = $this->pool->current();
        if ($this->isExpired($conn)) {
            // Long-lived scope (process fallback, worker loop) idle past
            // idleTimeout: the server may have dropped it, open a fresh one.
            $this->invalidate($conn);
            $conn = $this->pool->current();
        }
        $this->lastUsed[$conn] = time();

        return $conn;
    }

    /**
     * Discard a connection after an I/O error; the next get() opens a new one.
     */
    public function invalidate(object $conn): void {
        $this->checkFork();
        $this->broken[$conn] = true;
        $this->pool->invalidateCurrent();
    }

    /**
     * Close idle connections unused for idleTimeout (IdleCheckRegistry tick).
     * Connections in use by a coroutine are never touched here.
     */
    public function closeIdle(): void {
        if ($this->pool === null || $this->pid !== getmypid() || $this->idleTimeout <= 0) {
            return;
        }

        $keep = [];
        foreach ($this->idle as $conn) {
            if ($this->isExpired($conn)) {
                $this->closeQuietly($conn);
            } else {
                $keep[] = $conn;
            }
        }
        $this->idle = $keep;
    }

    public function getIdleCount(): int {
        return $this->pid === getmypid() ? count($this->idle) : 0;
    }

    private function isExpired(object $conn): bool {
        if ($this->idleTimeout <= 0) {
            return false;
        }
        return isset($this->lastUsed[$conn]) && (time() - $this->lastUsed[$conn]) >= $this->idleTimeout;
    }

    private function checkFork(): void {
        $pid = getmypid();
        if ($this->pool !== null && $this->pid === $pid) {
            return;
        }

        if ($this->pool !== null) {
            self::$abandoned[] = [$this->pool, $this->idle];
        }

        $this->pid = $pid;
        $this->idle = [];
        $this->freeIds = [];
        $this->nextId = 0;
        $this->broken = new SplObjectStorage();
        $this->lastUsed = new SplObjectStorage();
        $this->ids = new SplObjectStorage();

        $this->pool = new CoroutineScopedPool(
            fn(): object => $this->borrow(),
            $this->scopes ?? CoroutineScopeProviders::shared(),
            fn(object $conn) => $this->release($conn),
            fn(object $conn): bool => !isset($this->broken[$conn]),
            null,
            'memcache:' . $this->name,
            $this->maxConnections,
            $this->maxWaitMs
        );
    }

    private function borrow(): object {
        while ($this->idle) {
            $conn = array_pop($this->idle);
            if ($this->isExpired($conn)) {
                $this->closeQuietly($conn);
                continue;
            }
            return $conn;
        }

        $slot = $this->freeIds ? array_pop($this->freeIds) : ++$this->nextId;
        try {
            $conn = ($this->connect)($this->name . '-' . getmypid() . '-' . $slot);
        } catch (Throwable $e) {
            $this->freeIds[] = $slot;
            throw $e;
        }
        $this->ids[$conn] = $slot;
        $this->lastUsed[$conn] = time();

        return $conn;
    }

    private function release(object $conn): void {
        if (isset($this->broken[$conn]) || count($this->idle) >= $this->maxIdle) {
            $this->closeQuietly($conn);
            return;
        }
        $this->idle[] = $conn;
    }

    private function closeQuietly(object $conn): void {
        if (isset($this->ids[$conn])) {
            $this->freeIds[] = $this->ids[$conn];
        }
        $this->ids->detach($conn);
        $this->broken->detach($conn);
        $this->lastUsed->detach($conn);
        try {
            if ($this->close !== null) {
                ($this->close)($conn);
            } else if (method_exists($conn, 'close')) {
                $conn->close();
            }
        } catch (Throwable) {
        }
    }
}
