# WinterBoot Module - Distributed Task Computing Engine (DTCE)

Winter DTCE is a real-time Distributed Task Computing Engine.

> Task is a small logical unit that makes progress toward a unified goal of the system.

> Job is collection of similar tasks.

DTCE provides following capabilities

- Execute a task synchronously & asynchronously

- Execute big Job (contains many tasks) synchronously & asynchronously

- Allows persistent storage backend so that tasks won't get lost on system restarts or failures.


## Setup

To enable DTCE module in applications, append following code to **application.yml**

```yaml

modules:
    -   module: dev\winterframework\dtce\DtceModule
        enabled: true
        configFile: dtce-config.yml

```

#### PHP Extensions

- `swoole extension is required`

- `redis extension is needed if  Redis is being used as backend store`

- `rdkafka extension is needed if Kafka is being used as queue`

# Distributed Task Computing Engine

There are three main components

1. Task Queue

2. Task Store

3. Task Worker

## 1. Task Queue

Any executable task will be queued in to the Queue, then processed by the worker later.

Any Queue implementation must implement [TaskQueueHandler](src/task/storage/TaskQueueHandler.php).

This module provides four implementations out-of-the box

**1.1. Local Queue** - dev\winterframework\dtce\task\storage\TaskQueueShared
> Make sure `winter.queue.port` is setup in application.yml
> 
> This is local to the system only, all processes share same queue.

**1.2. Redis Queue** - dev\winterframework\dtce\task\storage\TaskQueueRedis

> Distributed

**1.3. Kafka Queue** - dev\winterframework\dtce\task\storage\TaskQueueKafka

> Distributed

**1.4. Pdbc Queue** - dev\winterframework\dtce\task\storage\TaskQueuePdbc

> Distributed

## 2. Task Store

Input data to worker and Output data from worker will be stored in to Task Store.

Task Store implementation must implement [TaskIOStorageHandler](src/task/storage/TaskIOStorageHandler.php)

This module provides three implementations out-of-the box

**2.1. Disk Store** - dev\winterframework\dtce\task\storage\TaskIOStorageDisk

> Note: Local Disks are not distributed, it only works when application is running on Single Node.
> 
> If application is running on multiple nodes, then you need to use NFS, HDFS, GlusterFS, or any distributed file system

**2.1. Memory KV Store** - dev\winterframework\dtce\task\storage\TaskIOStorageKvStore

> Make sure `winter.kv.port` is setup in application.yml
> 
> This is local to the system only, all processes share same KV store.

**2.2. Redis Store** - dev\winterframework\dtce\task\storage\TaskIOStorageRedis

> Distributed

**2.3. Pdbc Store** - dev\winterframework\dtce\task\storage\TaskIOStoragePdbc

> Distributed

## 3. Task Worker

This is actual implementation of your work.

Task worker must implement [TaskWorker](src/task/worker/TaskWorker.php)


Example Worker: Count number of words in given string
```phpt

class WordCounter extends AbstractTaskWorker {

    public function work(mixed $input): TaskOutput {
        if (!is_scalar($input)) {
            return new NumericOutput(0);
        }

        $input = '' . $input;

        return new NumericOutput(str_word_count($input));
    }
}

```

Example Execution:
```phpt
#[Autowired]
private TaskExecutionServiceFactory $factory;


# Name of the Task "wordCount", provided in dtce-config.yml , see next section for more details.
$executor = $this->factory->executionService("wordCount");

$result = $executor->executeTask("Some text here. Every application has some number of tasks that run in the background.");

if ($result->isSuccess()) {
    print "Task SUCCESS: " . $result->getResult() . " words.\n";
} else {
    print "Task FAILED!\n";
}


```

# DTCE Configuration (dtce-config.yml)

Example: dtce-config.yml

```yaml
tasks:
    -   name: wordCount
        storage:
            handler: dev\winterframework\dtce\task\storage\TaskIOStorageDisk
            path: /tmp
        worker:
            total: 4
            class: com\company\winter\task\word\WordCounter
        queue:
            handler: dev\winterframework\dtce\task\storage\TaskQueueRedis
    
    -   name: another-task
        ...
        ...
```


#### Full Configuration

```yaml

tasks:
    -   name: name-of-the-task
        
        # TASK STORAGE Configuration
        storage:
            handler: dev\winterframework\dtce\task\storage\TaskIOStorage***
            
            # TaskIOStorageDisk handler specific
            path: /folder/path

            # TaskIOStoragePdbc handler specific
            pdbc:
                bean:  # optional, unless if you have your own PdbcTemplate implementation
                entity: # optional, default to "TaskIoTable", you need Table get created before 
                ttl: #optional,  in seconds, Time to Keep Stored records, default to 4 hours, they will get delete after that
            
            # TaskIOStorageRedis handler specific
            redis:
                bean: # optional, unless if you have redis beanName.  see Redis module for more details.
                ttl: #optional,  in seconds, Time to Keep Stored records, default to 4 hours, they will get delete after that
        
        
        
        # TASK WORKER configuration
        worker:
            total: 10  # Number of workers to span. This is Concurrency setting.
            class: com\company\winter\task\word\WordCounter
        
        
        # TASK QUEUE configuration
        queue:
            handler: dev\winterframework\dtce\task\storage\TaskQueue**
            
            readTimeoutMs: # milli seconds to wait for while reading data from queue
            writeTimeoutMs:

            # TaskQueuePdbc specific
            pdbc:
                bean:  # optional, unless if you have your own PdbcTemplate implementation
                entity: # optional, default to "TaskQueueTable", you need Table get created before 
            
            # TaskQueueRedis specific
            redis:
                bean: # optional, unless if you have redis beanName.  see Redis module for more details.
                key:  # optional, if you want to use specific List Key

            # TaskQueueKafka specific
            kafka:
                bootstrap.servers: 127.0.0.1  # Kafka nodes
                topic: topic1  # Kafka Topic Name
                consumer:
                    -   auto.commit.interval.ms: 100
                        auto.commit.enable: true
                        # as many RDKAFKA settings here see https://github.com/edenhill/librdkafka/blob/master/CONFIGURATION.md
                producer:
                    -   message.max.bytes: 10485760
                        retries: 5
                        # as many RDKAFKA settings here see https://github.com/edenhill/librdkafka/blob/master/CONFIGURATION.md

```
