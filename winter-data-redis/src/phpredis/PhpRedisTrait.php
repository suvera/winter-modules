<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\phpredis;

trait PhpRedisTrait {
    protected int $lastAccessTime = 0;
    protected int $lastIdleCheck = 0;
    protected int $idleTimeout = 0;

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