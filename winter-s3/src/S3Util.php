<?php
declare(strict_types=1);

namespace dev\winterframework\s3;

use Aws\S3\S3Client;
use dev\winterframework\util\log\Wlf4p;
use dev\winterframework\sqs\SwooleHttpHandler;

class S3Util {
    use Wlf4p;

    private static array $clients = [];

    public static function buildClient(array $config): S3Client {
        $key = md5(serialize($config));
        if (!isset(self::$clients[$key])) {
            // Use PHP's built-in stream wrapper HTTP handler instead of Swoole's
            // coroutine HTTP client to avoid CURLOPT_PROTOCOLS_STR (10318) error
            // which is not supported by Swoole's cURL implementation when used
            // with AWS SDK's WrappedHttpHandler.
            if (!isset($config['http_handler']) && extension_loaded('swoole')) {
                $config['http_handler'] = new SwooleHttpHandler();
            }
            self::$clients[$key] = new S3Client($config);
        }

        return self::$clients[$key];
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
