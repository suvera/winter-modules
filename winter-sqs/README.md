# WinterBoot Module - SQS

AWS SQS module for WinterBoot providing both producer (send) and
consumer (receive / process) capabilities, modelled after the Kafka
and S3 modules.

## Setup

Add `winter-sqs` directory path to your project's `composer.json`
`autoload.psr-4` block:

```json
{
    "autoload": {
        "psr-4": {
            "dev\\winterframework\\sqs\\": "winter-sqs/src/"
        }
    }
}
```

Also, make sure that AWS SDK for PHP is installed:

```bash
composer require aws/aws-sdk-php
```

To enable the SQS module, add the following to your **application.yml**:

```yaml
modules:
    - module: dev\winterframework\sqs\SqsModule
      enabled: true
      configFile: sqs-config.yml
```

**configFile** is a file path (relative to config dir or absolute path).

## sqs-config.yml

```yaml
sqs:
    # Named connections define how to reach AWS SQS (region, credentials, ...).
    # At runtime you refer to a connection by name and pass the queue name
    # separately.
    connections:
        -   name: __default__
            version: latest
            region: us-east-1
            retries: 3
            delaySeconds: 0

        -   name: primary
            region: us-east-1

        -   name: secondary
            region: ap-south-1
            credentials:
                -   key: a
                    secret: b
                    token: c

    # Consumers poll a queue through a named connection.
    consumers:
        -   name: __default__
            version: latest
            region: us-east-1
            waitTimeSeconds: 20
            maxNumberOfMessages: 10
            visibilityTimeout: 30
            pollIntervalMs: 100

        -   name: MyQueue1-consumer
            connection: primary
            queueName: MyQueue1
            workerNum: 1
            workerClass: some\package\className
            transientExceptions: []

        -   name: MyQueue2-consumer
            connection: secondary
            queueName: MyQueue2
            workerNum: 2
            workerClass: some\package2\className2
```

## Autowired Services

### SqsService

[`SqsService`](src/SqsService.php) is the primary service for sending messages and
managing queues. Inject it with `#[Autowired]`:

```php
use dev\winterframework\sqs\SqsService;
use dev\winterframework\stereotype\Autowired;

class MyService {

    #[Autowired]
    private SqsService $sqsService;

    public function doSomething(): void {
        // Send a message through the "primary" connection to "MyQueue1"
        $this->sqsService->send('primary', 'MyQueue1', ['orderId' => 123]);

        // Create a new queue
        $url = $this->sqsService->createQueue('primary', 'MyNewQueue');

        // List queues with a prefix
        $queues = $this->sqsService->listQueues('primary', 'My');

        // Get queue attributes
        $attrs = $this->sqsService->getQueueAttributes('primary', 'MyQueue1');

        // Delete a queue
        $this->sqsService->deleteQueue('primary', 'MyOldQueue');

        // Purge all messages from a queue
        $this->sqsService->purgeQueue('primary', 'MyQueue1');
    }
}
```

### SqsConnections

[`SqsConnections`](src/SqsConnections.php) holds all registered connections indexed
by name. Use it when you need direct access to a specific connection:

```php
use dev\winterframework\sqs\SqsConnection;
use dev\winterframework\sqs\SqsConnections;
use dev\winterframework\stereotype\Autowired;

class MyService {

    #[Autowired]
    private SqsConnections $connections;

    public function doSomething(): void {
        // Get a specific connection by name
        $conn = $this->connections['primary'];

        // Access the raw AWS SqsClient
        $client = $conn->getRawClient();

        // Use the SqsClient directly for advanced operations
        $result = $client->getQueueUrl(['QueueName' => 'MyQueue1']);
    }
}
```

### SqsConnection (individual bean)

Each named connection is also registered as an individual bean with the
suffix `_connection`. You can autowire a specific connection by name:

```php
use dev\winterframework\sqs\SqsConnection;
use dev\winterframework\stereotype\Autowired;

class MyService {

    /**
     * Bean name matches the connection name + "_connection" suffix.
     * For a connection named "primary", the bean name is "primary_connection".
     */
    #[Autowired(name: 'primary_connection')]
    private SqsConnection $primaryConnection;

    public function doSomething(): void {
        // Access the raw AWS SqsClient for this connection
        $client = $this->primaryConnection->getRawClient();

        // Get connection metadata
        echo $this->primaryConnection->getName();   // "primary"
        echo $this->primaryConnection->getRegion(); // "us-east-1"
    }
}
```

## Connection Properties

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| name | string | yes | — | Unique connection name |
| region | string | yes | — | AWS region (e.g. us-east-1) |
| version | string | no | latest | API version |
| credentials | array | no | — | AWS key/secret/token. Omit for IAM roles |
| retries | int | no | 3 | Send retry count |
| delaySeconds | int | no | 0 | Default message delay in seconds |
| messageAttributes | object | no | {} | Default message attributes |
| messageGroupId | string | no | — | FIFO message group id |
| messageDeduplicationId | string | no | — | FIFO deduplication id |

## Using IAM roles (no keys required)

When your application runs on AWS infrastructure (EKS, EC2, Lambda,
ECS, ...) you do **not** need to specify `credentials`.  The AWS SDK
automatically picks up the IAM role through the default credential
chain.

### EKS IRSA example

1. Create an IAM role with the required SQS permissions.
2. Annotate your Kubernetes ServiceAccount with the role ARN:
   `eks.amazonaws.com/role-arn: arn:aws:iam::123456789:role/my-sqs-role`
3. Omit the `credentials` block – the SDK uses the IRSA-provided
   credentials automatically.

## Consumer Properties

| Property | Type | Required | Default | Description |
|---|---|---|---|---|
| name | string | yes | — | Unique consumer name |
| connection | string | yes | — | Name of the SQS connection to use |
| queueName | string | if queueUrl not given | — | SQS queue name to poll |
| queueUrl | string | if queueName not given | — | Full SQS queue URL |
| workerNum | int | no | 1 | Number of worker threads |
| workerClass | string | yes | — | PHP class implementing Consumer |
| waitTimeSeconds | int | no | 20 | Long-polling wait time (max 20) |
| maxNumberOfMessages | int | no | 10 | Max messages per poll (max 10) |
| visibilityTimeout | int | no | 30 | Visibility timeout in seconds |
| pollIntervalMs | int | no | 0 | Sleep between poll cycles |
| retries | int | no | 1 | Retry count for transient errors |
| retryWaitMs | int | no | 300 | Wait between retries |
| transientExceptions | array | no | [] | Exception classes to retry on |

## How to write a consumer worker

Implement the [`Consumer`](src/consumer/Consumer.php) interface
(or extend [`AbstractConsumer`](src/consumer/AbstractConsumer.php)):

### Example consumer worker

```php
<?php
namespace some\package;

use dev\winterframework\sqs\consumer\AbstractConsumer;
use dev\winterframework\sqs\consumer\ConsumerRecord;
use dev\winterframework\sqs\consumer\ConsumerRecords;

class MySqsWorker extends AbstractConsumer {

    public function consume(ConsumerRecords $records): void {
        foreach ($records as $record) {
            /** @var ConsumerRecord $record */
            self::logInfo('Received message: ' . $record->getBody()
                . ', MessageId: ' . $record->getMessageId()
                . ', Queue: ' . $record->getQueueName());

            // Process the message body
            $data = json_decode($record->getBody(), true);

            // Access message attributes if needed
            $attrs = $record->getMessageAttributes();
        }
    }
}