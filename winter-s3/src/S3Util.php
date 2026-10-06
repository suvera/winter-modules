<?php
declare(strict_types=1);

namespace dev\winterframework\s3;

use Aws\S3\S3Client;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class S3Util {
    use Wlf4p;

    private static array $clients = [];

    public static function buildClient(array $config): S3Client {
        $key = self::cacheKey($config);
        if ($key === null || !isset(self::$clients[$key])) {
            // Route both API requests (http_handler) and default-chain
            // credential fetching (IMDS/ECS via `client`) through Swoole's
            // coroutine HTTP client to avoid the CURLOPT_PROTOCOLS_STR (10318)
            // error from Swoole's cURL shim.
            $config = SwooleHttpHandler::applyToClientConfig($config);
            $client = new S3Client($config);
            if ($key === null) {
                return $client;
            }
            self::$clients[$key] = $client;
        }

        return self::$clients[$key];
    }

    /**
     * Client cache key, or null when the config holds something that cannot
     * be serialized (closures, e.g. a credential provider): build uncached.
     */
    private static function cacheKey(array $config): ?string {
        try {
            return md5(serialize($config));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Winter Boot flattens map-form config blocks into dotted keys
     * ("credentials.key" => ...). Returns [nested values for $prefix,
     * $config without those keys].
     */
    public static function extractDottedValues(array $config, string $prefix): array {
        $values = [];
        $needle = $prefix . '.';
        foreach ($config as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, $needle)) {
                continue;
            }
            $suffix = substr($key, strlen($needle));
            if ($suffix === '') {
                continue;
            }
            self::setByPath($values, explode('.', $suffix), $value);
            unset($config[$key]);
        }

        return [$values, $config];
    }

    private static function setByPath(array &$target, array $path, mixed $value): void {
        $segment = array_shift($path);
        if ($path === []) {
            $target[$segment] = $value;
            return;
        }
        if (!isset($target[$segment]) || !is_array($target[$segment])) {
            $target[$segment] = [];
        }
        self::setByPath($target[$segment], $path, $value);
    }

    public static function stringify(mixed $message): string {
        if (is_string($message)) {
            return $message;
        }
        if (is_scalar($message) || is_null($message)) {
            return strval($message);
        }

        return json_encode($message, JSON_UNESCAPED_SLASHES);
    }

}
