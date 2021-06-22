<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

use Co\System;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\Job;
use dev\winterframework\dtce\task\JobResult;
use dev\winterframework\dtce\task\server\TaskServer;
use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;
use dev\winterframework\dtce\task\TaskIds;
use dev\winterframework\dtce\task\TaskObject;
use dev\winterframework\dtce\task\TaskResult;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;

class TaskExecutionServiceImpl implements TaskExecutionService {
    use Wlf4p;

    protected TaskIOStorageHandler $storage;

    public function __construct(
        protected ApplicationContext $ctx,
        protected TaskServer $taskServer,
        protected array $taskDef,
        protected array $dtceConfig
    ) {
        $storageHandler = $this->taskDef['storage.handler'] ?? '--NONE--';

        TypeAssert::objectOfIsA($storageHandler, TaskIOStorageHandler::class,
            'Task Storage Handler "storage.handler" must implement ' . TaskIOStorageHandler::class);
        $this->storage = new $storageHandler($this->ctx, $this->taskDef);
    }

    public function getTaskName(): string {
        return $this->taskDef['name'];
    }

    public function executeJob(Job $job): JobResult {
        $tasks = $job->getTasks();

        $pending = [];
        foreach ($tasks as $index => $input) {
            $taskId = $this->sendTask($input);
            $pending[$index] = $taskId;
        }

        $result = new JobResult();

        while (1) {

            if (count($pending) == 0) {
                break;
            }

            foreach ($pending as $index => $taskId) {
                $task = $this->getTask($taskId);
                if ($task === null) {
                    self::logInfo('DTCE Server missing  Task record ' . $taskId);
                } else if ($task->getStatus() === TaskStatus::FINISHED
                    || $task->getStatus() === TaskStatus::STOPPED
                    || $task->getStatus() === TaskStatus::ERRORED
                ) {
                    $ts = new TaskResult(
                        $task->getStatus(),
                        $task->getOutputId(),
                        $this->storage
                    );
                    $result->addResult($index, $ts);
                    unset($pending[$index]);
                }
                System::sleep(0.1);
            }

            System::sleep(0.2);
        }

        return $result;
    }

    public function addJob(Job $job): TaskIds {
        $tasks = $job->getTasks();
        $result = new TaskIds();

        foreach ($tasks as $index => $input) {
            $taskId = $this->sendTask($input);
            $result->addResult($index, $taskId);
        }

        return $result;
    }

    public function executeTask(mixed $input): TaskResult {
        $taskId = $this->sendTask($input);

        while (1) {
            $task = $this->getTask($taskId);
            if ($task === null) {
                self::logInfo('DTCE Server missing taskId ' . $taskId);
            } else if ($task->getStatus() === TaskStatus::FINISHED
                || $task->getStatus() === TaskStatus::STOPPED
                || $task->getStatus() === TaskStatus::ERRORED
            ) {
                return new TaskResult(
                    $task->getStatus(),
                    $task->getOutputId(),
                    $this->storage
                );
            }

            System::sleep(0.1);
        }
    }

    protected function sendTask(mixed $input): string {
        $dataId = null;

        if (!is_null($input)) {
            $dataId = 'in-' . Uuid::uuid4()->toString();
            $this->storage->put($dataId, serialize($input));
        }

        $task = new TaskObject();
        $task->setInputId($dataId);
        $task->setCreatedOn(time());
        $task->setName($this->getTaskName());

        $success = $this->taskServer->addTask($task);

        if (!$success || !$task->getId()) {
            throw new DtceException('Could not create Task on the server');
        }

        self::logInfo('Task sent to server, TaskId: ' . $task->getId());
        return $task->getId();
    }

    public function addTask(mixed $input): string {
        return $this->sendTask($input);
    }

    protected function getTask(string $taskId): ?TaskObject {
        $task = $this->taskServer->getTask($this->getTaskName(), $taskId);

        if (!$task) {
            throw new DtceException('Could not get task status from server ');
        }
        return $task;
    }

    public function taskStatus(string $taskId): ?int {
        $task = $this->getTask($taskId);
        return $task ? $task->getStatus() : TaskStatus::UNKNOWN;
    }

    public function stopTask(string $taskId): void {
        $this->taskServer->stopTask($this->getTaskName(), $taskId);
    }

    public function newJob(): Job {
        return new Job($this->getTaskName());
    }

}