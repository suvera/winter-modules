<?php
declare(strict_types=1);

namespace dev\winterframework\data\memcache\util;

use dev\winterframework\core\System;
use Swoole\Coroutine;

/**
 * Hold the first connect until bootUpTimeMs has passed since the template
 * was built (e.g. a memcached sidecar that starts with the app).
 */
final class BootUpDelay {

    public static function await(int $startTimeMs, int $bootUpTimeMs): void {
        if ($bootUpTimeMs <= 0) {
            return;
        }

        $remainingMs = $bootUpTimeMs - (System::currentTimeMillis() - $startTimeMs);
        if ($remainingMs <= 0) {
            return;
        }

        if (extension_loaded('swoole') && Coroutine::getCid() > 0) {
            Coroutine::sleep($remainingMs / 1000);
        } else {
            usleep($remainingMs * 1000);
        }
    }
}
