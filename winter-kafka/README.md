# WinterBoot Module - Kafka

Winter Kafka is a module that provides easy configuration and access to Kafka functionality from WinterBoot applications.


## Setup

- This requires `swoole` & `rdkafka` php extensions

```shell
pecl install swoole
pecl install rdkafka
```

Add modules to your project with composer

```shell
composer require suvera/winter-modules
```

To enable Kafka module in applications, append following code to **application.yml**

```yaml

modules:
    - module: dev\winterframework\kafka\KafkaModule
      enabled: true
      configFile: kafka-config.yml

```

**configFile** is a file path (relative to config dir or absolute path)


## kafka-config.yml

```yaml
bootstrap.servers: 127.0.0.1:9001

# Consumer configuration and groups
consumers:
    - name: __default__
      transientExceptions: []

    - name: group1-name
      topics: [topic1-name, topic99-name]
      workerNum: 1
      workerClass: some\package\className

    - name: group2-name
      topics: [topic2-name]
      workerNum: 1
      workerClass: some\package2\className2

# Producer configuration
producers:
    - name: __default__
      message.max.bytes: 10485760
      retries: 5

    - name: p-group1
      topic: p-topic1

    - name: p-group2
      topic: p-topic2

# End
```

**workerNum** - Number of consumer workers to be started.

## Autowired Services

**[KafkaService](src/KafkaService.php)** is a service available to applications by default.

1. Consumer workers will be auto-started by Framework by default.


2. Producer configurations are auto-enabled by default.


3. "**\_\_default\_\_**" consumer/producer can contain any [rdkafka settings](https://github.com/edenhill/librdkafka/blob/master/CONFIGURATION.md). Default settings are applicable to all producers/consumers.
   

4. Each producer/consumer may also have specific [rdkafka settings](https://github.com/edenhill/librdkafka/blob/master/CONFIGURATION.md), those settings applicable to that producer/consumer only.


```phpt

#[Autowired]
private KafkaService $kafkaService;

$this->kafkaService->produce(....message....);

```


## How to write a consumer worker

Consumer worker must implement [Consumer](src/consumer/Consumer.php) interface.

#### Example consumer worker

```phpt
class TestKafkaConsumer extends AbstractConsumer {

    public function consume(ConsumerRecords $records): void {

        foreach ($records as $record) {
        
            // Do something with ConsumerRecord
             
            /** @var ConsumerRecord $record */
            self::logInfo("Received a message "
            
                . ' Message: ' . $record->getValue()
                
                . ', Topic: ' . $record->getTopic()
                . ', Group: ' . $record->getGroupName()
                
                . ', Time: ' . $record->getTimestamp()
                . ', Headers: ' . json_encode($record->getHeaders())
                
                . ', Offset: ' . $record->getOffset()
                . ', Partition: ' . $record->getPartition()
            );
            
        }

    }

}
```