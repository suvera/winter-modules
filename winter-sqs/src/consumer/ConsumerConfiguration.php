<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\sqs\consumer;

use Aws\Sqs\SqsClient;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\sqs\SqsConnection;
use dev\winterframework\util\log\Wlf4p;

class ConsumerConfiguration {
    use Wlf4p;

    private static array $defaults = [
        'waitTimeSeconds' => 20,
        'maxNumberOfMessages' => 10,
        'visibilityTimeout' => 30,
        'pollIntervalMs' => 0,
    ];

    private string $name = '';
    private string $connection = '';
    private string $queueName = '';
    private string $queueUrl = '';
    private int $workerNum = 1;
    private string $workerClass = '';
    private int $retries = 1;
    private int $retryWaitMs = 300;
    private array $transientExceptions = [];

    private array $config = [];
    private ?SqsConnection $sqsConnection = null;

    /**
     * ConsumerConfiguration constructor.
     */
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
        return $this->getConnection()->getRawClient();
    }

    public function getConnection(): SqsConnection {
        if (!isset($this->sqsConnection)) {
            $this->buildConnection();
        }

        return $this->sqsConnection;
    }

    protected function buildConnection(): void {
        if ($this->connection) {
            /** @var SqsConnection $conn */
            $conn = $this->ctx->beanByName($this->connection . '_connection');
            $this->sqsConnection = $conn;
        } else {
            // Build an anonymous connection from inline settings (region, credentials, ...)
            $args = $this->config;
            $args['name'] = $this->name;
            $this->sqsConnection = new SqsConnection($args, $this->ctx);
        }
    }

    public function resolveQueueUrl(): string {
        if ($this->queueUrl) {
            return $this->queueUrl;
        }

        $queueName = $this->queueName ?: $this->getName();
        $result = $this->getRawClient()->getQueueUrl(['QueueName' => $queueName]);

        $this->queueUrl = strval($result['QueueUrl'] ?? '');

        return $this->queueUrl;
    }

    public function getRetries(): int {
        return ($this->retries <= 0) ? 1 : $this->retries;
    }

    public function getRetryWaitMs(): int {
        return $this->retryWaitMs;
    }

    public function getName(): string {
        return $this->name;
    }

    public function setName(string $name): ConsumerConfiguration {
        $this->name = $name;
        return $this;
    }

    public function getConnectionName(): string {
        return $this->connection;
    }

    public function setConnectionName(string $connection): ConsumerConfiguration {
        $this->connection = $connection;
        return $this;
    }

    public function getQueueName(): string {
        return $this->queueName;
    }

    public function setQueueName(string $queueName): ConsumerConfiguration {
        $this->queueName = $queueName;
        return $this;
    }

    public function getQueueUrl(): string {
        return $this->queueUrl;
    }

    public function setQueueUrl(string $queueUrl): ConsumerConfiguration {
        $this->queueUrl = $queueUrl;
        return $this;
    }

    public function getWorkerNum(): int {
        return $this->workerNum;
    }

    public function setWorkerNum(int $workerNum): ConsumerConfiguration {
        $this->workerNum = $workerNum;
        return $this;
    }

    public function getWorkerClass(): string {
        return $this->workerClass;
    }

    public function setWorkerClass(string $workerClass): ConsumerConfiguration {
        $this->workerClass = $workerClass;
        return $this;
    }

    public function getTransientExceptions(): array {
        return $this->transientExceptions;
    }

    public function setTransientExceptions(array $transientExceptions): ConsumerConfiguration {
        $this->transientExceptions = $transientExceptions;
        return $this;
    }

}
