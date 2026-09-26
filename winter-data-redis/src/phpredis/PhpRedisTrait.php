<?php

declare(strict_types=1);

namespace dev\winterframework\data\redis\phpredis;

trait PhpRedisTrait {
    protected int $lastAccessTime = 0;
    protected int $lastIdleCheck = 0;
    protected int $idleTimeout = 0;
    protected int $connectedPid = 0;

    /**
     * Swoole forks workers after modules boot, so a connection opened in the
     * master process is inherited by every worker sharing one socket. Replies
     * then get mixed across processes (e.g. XADD receiving an XREADGROUP
     * reply). Drop the inherited connection on first use in a new process;
     * each __call reconnects through its own null path.
     */
    protected function dropForkedConnection(): void {
        if ($this->connectedPid !== 0 && $this->connectedPid !== getmypid()) {
            $conn = $this->redis ?? null;
            $this->redis = null;
            if (is_object($conn) && method_exists($conn, 'close')) {
                try {
                    $conn->close();
                } catch (\Throwable) {
                }
            }
        }

        $this->connectedPid = getmypid();
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
        $this->redis->close();
        $this->redis = null;
    }
}
