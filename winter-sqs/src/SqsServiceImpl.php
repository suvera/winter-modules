<?php
/** @noinspection PhpUnused */
declare(strict_types=1);

namespace dev\winterframework\sqs;

use Aws\Sqs\SqsClient;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\sqs\consumer\ConsumerConfiguration;
use dev\winterframework\sqs\consumer\ConsumerConfigurations;
use dev\winterframework\sqs\exception\SqsException;
use dev\winterframework\stereotype\Autowired;
use dev\winterframework\util\log\Wlf4p;

class SqsServiceImpl implements SqsService {
    use Wlf4p;

    protected SqsConnections $connections;
    protected ConsumerConfigurations $consumers;

    #[Autowired]
    private WinterServer $wServer;

    #[Autowired]
    private ApplicationContext $appCtx;

    private bool $consumerStarted = false;

    public function __construct() {
        $this->connections = new SqsConnections();
        $this->consumers = new ConsumerConfigurations();
    }

    // ------------------------------------------------------------------------
    //  SEND
    // ------------------------------------------------------------------------

    public function send(
        string $connectionName,
        string $queueName,
        mixed $message,
        ?array $messageAttributes = null,
        ?array $queueArgs = null
    ): bool {
        $connection = $this->resolveConnection($connectionName);
        SqsUtil::sendMessage($connection, $queueName, $message, $messageAttributes, $queueArgs);

        return true;
    }

    public function sendAsync(
        string $connectionName,
        string $queueName,
        mixed $message,
        ?array $messageAttributes = null,
        ?array $queueArgs = null
    ): bool {
        $connection = $this->resolveConnection($connectionName);
        SqsUtil::sendMessage($connection, $queueName, $message, $messageAttributes, $queueArgs);

        return true;
    }

    // ------------------------------------------------------------------------
    //  QUEUE MANAGEMENT
    // ------------------------------------------------------------------------

    public function createQueue(
        string $connectionName,
        string $queueName,
        array $attributes = []
    ): string {
        $client = $this->resolveClient($connectionName);

        $args = ['QueueName' => $queueName];
        if ($attributes) {
            $args['Attributes'] = $attributes;
        }

        $result = $client->createQueue($args);

        return strval($result['QueueUrl'] ?? '');
    }

    public function deleteQueue(string $connectionName, string $queueName): bool {
        $client = $this->resolveClient($connectionName);
        $queueUrl = $this->resolveQueueUrl($client, $queueName);

        $client->deleteQueue(['QueueUrl' => $queueUrl]);

        return true;
    }

    public function purgeQueue(string $connectionName, string $queueName): bool {
        $client = $this->resolveClient($connectionName);
        $queueUrl = $this->resolveQueueUrl($client, $queueName);

        $client->purgeQueue(['QueueUrl' => $queueUrl]);

        return true;
    }

    public function getQueueUrl(string $connectionName, string $queueName): string {
        $client = $this->resolveClient($connectionName);

        return $this->resolveQueueUrl($client, $queueName);
    }

    public function listQueues(string $connectionName, string $prefix = '', int $maxResults = 1000): array {
        $client = $this->resolveClient($connectionName);

        $args = ['MaxResults' => $maxResults];
        if ($prefix) {
            $args['QueueNamePrefix'] = $prefix;
        }

        $result = $client->listQueues($args);

        $urls = $result['QueueUrls'] ?? [];

        return (array)$urls;
    }

    public function getQueueAttributes(
        string $connectionName,
        string $queueName,
        array $attributeNames = ['All']
    ): array {
        $client = $this->resolveClient($connectionName);
        $queueUrl = $this->resolveQueueUrl($client, $queueName);

        $result = $client->getQueueAttributes([
            'QueueUrl' => $queueUrl,
            'AttributeNames' => $attributeNames,
        ]);

        $attrs = $result['Attributes'] ?? [];

        return (array)$attrs;
    }

    public function setQueueAttributes(
        string $connectionName,
        string $queueName,
        array $attributes = []
    ): bool {
        $client = $this->resolveClient($connectionName);
        $queueUrl = $this->resolveQueueUrl($client, $queueName);

        $client->setQueueAttributes([
            'QueueUrl' => $queueUrl,
            'Attributes' => $attributes,
        ]);

        return true;
    }

    // ------------------------------------------------------------------------
    //  INTERNAL HELPERS
    // ------------------------------------------------------------------------

    protected function resolveConnection(string $name): SqsConnection {
        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        throw new SqsException('SQS connection not found: ' . $name);
    }

    protected function resolveClient(string $connectionName): SqsClient {
        return $this->resolveConnection($connectionName)->getRawClient();
    }

    protected function resolveQueueUrl(SqsClient $client, string $queueName): string {
        $result = $client->getQueueUrl(['QueueName' => $queueName]);

        return strval($result['QueueUrl'] ?? '');
    }

    // ------------------------------------------------------------------------
    //  CONSUMERS
    // ------------------------------------------------------------------------

    public function getConnections(): SqsConnections {
        return $this->connections;
    }

    public function getConsumers(): ConsumerConfigurations {
        return $this->consumers;
    }

    public function addConnection(SqsConnection $config): void {
        $this->connections[] = $config;
    }

    public function addConsumer(ConsumerConfiguration $config): void {
        $this->consumers[] = $config;
    }

    protected function startConsumer(ConsumerConfiguration $consumer, int $i): void {
        $ps = new SqsWorkerProcess($this->wServer, $this->appCtx, $consumer, $i + 1);
        $this->wServer->addProcess($ps);
    }

    public function beginConsume(): void {
        if ($this->consumerStarted) {
            return;
        }

        foreach ($this->consumers as $consumer) {
            /** @var ConsumerConfiguration $consumer */
            for ($i = 0; $i < $consumer->getWorkerNum(); $i++) {
                $this->startConsumer($consumer, $i);
            }
        }

        $this->consumerStarted = true;
    }

}