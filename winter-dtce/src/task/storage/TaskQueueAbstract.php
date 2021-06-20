<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\TaskObject;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\type\Queue;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;

abstract class TaskQueueAbstract implements TaskQueueHandler {
    use Wlf4p;

    protected Queue $queue;

    public function __construct(
        protected ApplicationContext $ctx,
        protected TaskIOStorageHandler $storage,
        protected array $taskDef,
        protected array $dtceConfig
    ) {
        $this->taskDef['queue.capacity'] = $this->taskDef['queue.capacity'] ?? 1;
        if (!isset($this->taskDef['queue.readTimeoutMs'])
            || !is_numeric($this->taskDef['queue.readTimeoutMs'])
            || $this->taskDef['queue.readTimeoutMs'] <= 0
        ) {
            $this->taskDef['queue.readTimeoutMs'] = 200;
        }
        if (!isset($this->taskDef['queue.writeTimeoutMs'])
            || !is_numeric($this->taskDef['queue.writeTimeoutMs'])
            || $this->taskDef['queue.writeTimeoutMs'] <= 0
        ) {
            $this->taskDef['queue.writeTimeoutMs'] = 200;
        }

        $this->queue = $this->buildTaskQueue();
    }

    abstract protected function buildTaskQueue(): Queue;

    protected function prepareTaskId(string $taskId): string {
        return 'task-' . $taskId;
    }

    public function get(string $taskId): ?TaskObject {
        $stream = $this->storage->getInputStream($this->prepareTaskId($taskId));
        if ($stream) {
            $c = $stream->read();
            $ret = unserialize($c);
            if ($ret === false) {
                self::logError('Could not unserialize ' . $c . ' for taskId ' . $taskId);
            } else {
                return $ret;
            }
        }
        return null;
    }

    public function delete(string $taskId): void {
        self::logInfo('Deleting task with taskId ' . $taskId);
        $this->storage->delete($this->prepareTaskId($taskId));
    }

    protected function saveTask(TaskObject $task): void {
        $storeId = $this->prepareTaskId($task->getId());
        $this->storage->put($storeId, serialize($task));
    }

    public function taskStatus(string $taskId, int $taskStatus): void {
        $task = $this->get($taskId);
        if (!$task) {
            return;
        }
        $task->setStatus($taskStatus);

        $this->saveTask($task);
    }

    public function taskOutput(string $taskId, string $dataId): void {
        $task = $this->get($taskId);
        if (!$task) {
            return;
        }
        $task->setOutputId($dataId);

        $this->saveTask($task);
    }

    public function pop(): ?TaskObject {
        $value = $this->queue->poll($this->taskDef['queue.readTimeoutMs']);
        if (!$value) {
            return null;
        }
        $task = $this->get($value);
        if (!$task) {
            return null;
        }

        $task->setStatus(TaskStatus::RUNNING);
        $this->saveTask($task);

        return $task;
    }

    public function push(TaskObject $task): void {
        if ($task->getId() == '') {
            $task->setId(Uuid::uuid4()->toString());
        }
        $task->setStatus(TaskStatus::QUEUED);

        $storeId = $this->prepareTaskId($task->getId());
        $this->saveTask($task);

        $value = $this->queue->add($task->getId(), $this->taskDef['queue.writeTimeoutMs']);
        if (!$value) {
            $this->storage->delete($storeId);
            throw new DtceException('[DTCE] Task Queue Capacity exceeded');
        }

        self::logInfo('Task added to store ' . $storeId);
    }

    public function taskName(): string {
        return $this->taskDef['name'];
    }

}