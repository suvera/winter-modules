<?php
declare(strict_types=1);

namespace dev\winterframework\sqs;

use Aws\Sqs\SqsClient;
use dev\winterframework\sqs\exception\SqsException;
use dev\winterframework\util\log\Wlf4p;
use Throwable;

class SqsUtil {
    use Wlf4p;

    private static array $clients = [];

    public static function buildClient(array $config): SqsClient {
        $key = md5(serialize($config));
        if (!isset(self::$clients[$key])) {
            // Use PHP's built-in stream wrapper HTTP handler instead of Swoole's
            // coroutine HTTP client to avoid CURLOPT_PROTOCOLS_STR (10318) error
            // which is not supported by Swoole's cURL implementation when used
            // with AWS SDK's WrappedHttpHandler.
            if (!isset($config['http_handler']) && extension_loaded('swoole')) {
                $config['http_handler'] = new SwooleHttpHandler();
            }
            self::$clients[$key] = new SqsClient($config);
        }

        return self::$clients[$key];
    }

    /**
     * @throws
     */
    public static function sendMessage(
        SqsConnection $connection,
        string $queueName,
        mixed $message,
        ?array $messageAttributes = null,
        ?array $queueArgs = null,
        ?callable $onSuccess = null,
        ?callable $onFailed = null
    ): void {
        $success = false;
        $exception = null;
        $trial = 0;
        $maxTries = intval($connection->getConfigVal('retries', 3));
        if ($maxTries <= 0) {
            $maxTries = 3;
        }

        while ($trial < $maxTries) {
            $trial++;
            try {
                self::doSendMessage($connection, $queueName, $message, $messageAttributes, $queueArgs);
                $success = true;
                break;
            } catch (Throwable $e) {
                self::logException($e);
                $exception = $e;
            }
        }

        if ($success) {
            if ($onSuccess != null) {
                $onSuccess($connection, $queueName, $message);
            }
        } else {
            if ($onFailed != null) {
                $onFailed($queueName, $message);
            }

            throw new SqsException('Could not send SQS message to ' . $queueName, 0, $exception);
        }
    }

    protected static function doSendMessage(
        SqsConnection $connection,
        string $queueName,
        mixed $message,
        ?array $messageAttributes = null,
        ?array $queueArgs = null
    ): void {
        $queueUrl = self::resolveQueueUrl($connection, $queueName);

        $args = [
            'QueueUrl' => $queueUrl,
            'MessageBody' => self::stringify($message),
        ];

        $delay = intval($connection->getConfigVal('delaySeconds', 0));
        if ($delay > 0) {
            $args['DelaySeconds'] = $delay;
        }

        $defaultAttributes = $connection->getConfigVal('messageAttributes', []);
        $attributes = array_merge($defaultAttributes, $messageAttributes ?? []);
        if ($attributes) {
            $args['MessageAttributes'] = $attributes;
        }

        $fifo = self::buildFifoOptions($connection, $queueArgs);
        if ($fifo) {
            $args = array_merge($args, $fifo);
        }

        $connection->getRawClient()->sendMessage($args);
    }

    public static function resolveQueueUrl(SqsConnection $connection, string $queueName): string {
        $result = $connection->getRawClient()->getQueueUrl(['QueueName' => $queueName]);

        return strval($result['QueueUrl'] ?? '');
    }

    protected static function buildFifoOptions(
        SqsConnection $connection,
        ?array $queueArgs = null
    ): array {
        $args = [];

        $groupId = $queueArgs['messageGroupId']
            ?? $connection->getConfigVal('messageGroupId', '');
        if ($groupId) {
            $args['MessageGroupId'] = $groupId;
        }

        $dedupId = $queueArgs['messageDeduplicationId']
            ?? $connection->getConfigVal('messageDeduplicationId', '');
        if ($dedupId) {
            $args['MessageDeduplicationId'] = $dedupId;
        }

        return $args;
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
