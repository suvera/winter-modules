<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\sqs;

use Aws\Sqs\SqsClient;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\sqs\StreamWrapperHttpHandler;
use dev\winterframework\util\log\Wlf4p;

/**
 * Represents a named AWS SQS connection. It holds the settings required to build an
 * SqsClient (region, credentials, version, ...) and lazily creates the underlying client.
 */
class SqsConnection {
    use Wlf4p;

    private static array $defaults = [
        'version' => 'latest',
    ];

    private string $name = '';
    private string $region = '';
    private string $version = 'latest';
    private array $credentials = [];

    private array $config = [];
    private ?SqsClient $rawClient = null;

    public function __construct(array $config, protected ApplicationContext $ctx) {
        foreach (self::$defaults as $key => $value) {
            if (isset($value)) {
                $this->config[$key] = $value;
            }
        }

        foreach ($config as $key => $value) {
            if (property_exists($this, $key) && $key != 'config') {
                $this->$key = $value;
            } else {
                $this->config[$key] = $value;
            }
        }
    }

    public function getConfig(): array {
        return $this->config;
    }

    public function getConfigVal(string $key, mixed $default = null): mixed {
        return $this->config[$key] ?? $default;
    }

    public function getRawClient(): SqsClient {
        if (!isset($this->rawClient)) {
            $this->buildClient();
        }

        return $this->rawClient;
    }

    protected function buildClient(): void {
        $args = $this->config;

        $args['version'] = $this->version ?: 'latest';
        $args['region'] = $this->region ?: ($args['region'] ?? '');

        if ($this->credentials) {
            $args['credentials'] = $this->credentials;
        }

        // Use plain PHP stream wrapper HTTP handler instead of Swoole's coroutine HTTP client
        // to avoid CURLOPT_PROTOCOLS_STR (10318) error which is not supported by Swoole's
        // cURL implementation when used with AWS SDK's WrappedHttpHandler
        if (!isset($args['http_handler']) && extension_loaded('swoole')) {
            $args['http_handler'] = new SwooleHttpHandler();
        }

        $this->rawClient = SqsUtil::buildClient($args);
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): SqsConnection {
        $this->name = $name;
        return $this;
    }

    public function getRegion(): string {
        return $this->region;
    }

    public function setRegion(string $region): SqsConnection {
        $this->region = $region;
        return $this;
    }

}
