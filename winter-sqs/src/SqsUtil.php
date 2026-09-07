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
            // Route both API requests (http_handler) and default-chain
            // credential fetching (IMDS/ECS via `client`) through Swoole's
            // coroutine HTTP client to avoid the CURLOPT_PROTOCOLS_STR (10318)
            // error from Swoole's cURL shim.
            $config = SwooleHttpHandler::applyToClientConfig($config);
            // Never log secret values: only the credential source shape.
            $creds = $config['credentials'] ?? null;
            self::logInfo('Building SQS client, region=' . strval($config['region'] ?? '')
                . ', endpoint=' . strval($config['endpoint'] ?? '(aws)')
                . ', credentials=' . (!isset($creds) ? 'none'
                    : (is_callable($creds) ? 'provider' : 'static'))
                . ', http_handler=' . (is_object($config['http_handler'] ?? null)
                    ? get_class($config['http_handler']) : 'default'));
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
