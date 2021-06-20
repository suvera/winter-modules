<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\TaskObject;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\type\Queue;
use Ramsey\Uuid\Uuid;
use Swoole\Table;

abstract class TaskQueueAbstract implements TaskQueueHandler {
    const MAX_RECORDS = 10000;

    protected Table $table;

    protected Queue $queue;

    public function __construct(
        protected ApplicationContext $ctx,
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
        if (!isset($this->taskDef['maxHistory'])
            || !is_numeric($this->taskDef['maxHistory'])
            || $this->taskDef['maxHistory'] < 1
        ) {
            $this->taskDef['maxHistory'] = self::MAX_RECORDS;
        }

        $this->queue = $this->buildTaskQueue();

        $this->table = new Table($this->taskDef['maxHistory']);
        $this->table->column('id', Table::TYPE_STRING, 36);
        $this->table->column('name', Table::TYPE_STRING, 128);
        $this->table->column('status', Table::TYPE_INT);
        $this->table->column('createdOn', Table::TYPE_INT);
        $this->table->column('updatedOn', Table::TYPE_INT);
        $this->table->column('inputId', Table::TYPE_STRING, 36);
        $this->table->column('outputId', Table::TYPE_STRING, 36);
        $this->table->create();
    }

    abstract protected function buildTaskQueue(): Queue;

    public function get(string $taskId): ?TaskObject {
        return $this->table[$taskId] ?? null;
    }

    public function delete(string $taskId): void {
        if (isset($this->table[$taskId])) {
            $data = $this->table[$taskId];
            if ($data['status'] != TaskStatus::QUEUED) {
                $this->table->del($taskId);
            }
        }
    }

    public function taskStatus(string $taskId, int $taskStatus): void {
        if (isset($this->table[$taskId])) {
            $this->table[$taskId]['status'] = $taskStatus;
            $this->table[$taskId]['updatedOn'] = time();
        }
    }

    public function taskOutput(string $taskId, string $dataId): void {
        if (isset($this->table[$taskId])) {
            $this->table[$taskId]['outputId'] = $dataId;
            $this->table[$taskId]['updatedOn'] = time();
        }
    }

    public function pop(): ?TaskObject {
        $value = $this->queue->poll($this->taskDef['queue.readTimeoutMs']);
        if (!$value) {
            return null;
        }
        if (!$this->table->exist($value)) {
            return null;
        }
        $data = $this->table[$value];
        $data['status'] = TaskStatus::RUNNING;
        $this->table[$value]['status'] = TaskStatus::RUNNING;
        return TaskObject::fromArray($data);
    }

    public function push(TaskObject $task): void {
        if ($task->getId() == '') {
            $task->setId(Uuid::uuid4()->toString());
        }
        $task->setStatus(TaskStatus::QUEUED);
        $this->table[$task->getId()] = $task->jsonSerialize();
        $value = $this->queue->add($task->getId(), $this->taskDef['queue.writeTimeoutMs']);
        if (!$value) {
            unset($this->table[$task->getId()]);
            throw new DtceException('[DTCE] Task Queue Capacity exceeded');
        }

        $gc = ceil($this->taskDef['maxHistory'] * 0.7);
        if ($this->table->count() > $gc) {
            foreach ($this->table as $id => $row) {
                if (($row['status'] != TaskStatus::QUEUED && $row['status'] != TaskStatus::RUNNING
                        && (time() - $row['updatedOn']) > 3600)
                    || (time() - $row['createdOn'] > 86400)
                ) {
                    $this->table->del($id);
                }
            }
        }
    }

    public function taskName(): string {
        return $this->taskDef['name'];
    }

}