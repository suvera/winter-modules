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

## Consumer Properties

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
name | Yes | | Consumer Group Name
topic | Yes (alias to 'topics') | | (string) Topic to consume, either 'topic' or 'topics' needed.
topics | Yes (alias to 'topic') | | (array) Array of Topics to consume,  either 'topic' or 'topics' needed.
workerNum | Yes | 1 | Number of consumer workers to be started
workerClass | Yes | | Actual Consumer class implementation, must be derived from AbstractConsumer
statsCallback | No | dev\winterframework\kafka\consumer\ConsumerLagMonitor | Sets the statistics report callback, must be derived from ConsumerStatisticsCallback. Set 0 to disable it.
errorCallback | No | dev\winterframework\kafka\consumer\ConsumerErrorCallbackDefault | Set error callback. The error callback is used by librdkafka to signal critical errors back to the application. Must be derived from ConsumerErrorCallback. Set 0 to disable it.
log_level | No | 6 (Integer) | Set log level value. EMERG = 0, ALERT = 1, CRIT = 2, ERR = 3, WARNING = 4, NOTICE = 5, INFO = 6, DEBUG = 7,
logCallback | No | dev\winterframework\kafka\KafkaLogCallbackDefault | Set log callback. You will get events according to log_level. Must be derived from KafkaLogCallback. Set 0 to disable it.
offsetCommitCallback | No | - | Set offset commit callback for use with consumer groups. Must be derived from ConsumerOffsetCommitCallback
rebalanceCallback | Yes | dev\winterframework\kafka\consumer\ConsumerRebalanceCallbackDefault |Set rebalance callback for use with coordinated consumer group balancing. Must be derived from ConsumerRebalanceCallback.

## Producer Properties

Name | Required | Default Value | Description
------------ | ------------ | ------------ | ------------
name | Yes | | Producer  Name
topic | Yes | | (string) Message will be prodcued to this Topic.
log_level | No | 6 (Integer) | Set log level value. EMERG = 0, ALERT = 1, CRIT = 2, ERR = 3, WARNING = 4, NOTICE = 5, INFO = 6, DEBUG = 7,
logCallback | No | dev\winterframework\kafka\KafkaLogCallbackDefault | Set log callback. You will get events according to log_level. Must be derived from KafkaLogCallback. Set 0 to disable it.

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