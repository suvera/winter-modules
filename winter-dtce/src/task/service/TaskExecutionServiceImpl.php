<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

use Co\System;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\server\CommandStatus;
use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;
use dev\winterframework\dtce\task\TaskCommand;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;
use Swoole\Client;

class TaskExecutionServiceImpl implements TaskExecutionService {
    use Wlf4p;

    protected Client $client;

    protected TaskIOStorageHandler $storage;

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $taskDef,
        protected array $dtceConfig
    ) {
        $this->client = new Client(SWOOLE_TCP | SWOOLE_KEEP);
        $clientSettings = $this->dtceConfig['client.settings'][0] ?? [];
        if ($clientSettings) {
            $this->client->set($clientSettings);
        }

        $storageHandler = $this->taskDef['storage.hHandler'] ?? '--NONE--';

        TypeAssert::objectOfIsA($storageHandler, TaskIOStorageHandler::class,
            'Task Storage Handler must implement ' . TaskIOStorageHandler::class);
        $this->storage = new $storageHandler($this->ctx, $this->taskDef);
    }

    public function getTaskName(): string {
        return $this->taskDef['name'];
    }

    protected function initClient(): void {
        if ($this->client->isConnected()) {
            return;
        }

        if (!$this->client->connect('127.0.0.1', $this->dtceConfig['server.port'], -1)) {
            self::logError('[DTCE] Could not connect to DTCE Task Server');
            throw new DtceException('Could not connect to DTCE Task Server');
        }
    }

    public function executeJob(Job $job): JobResult {
        $this->initClient();

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
                    throw new DtceException('DTCE Server missing  Task record ' . $taskId);
                } else if ($task['status'] === TaskStatus::FINISHED
                    || $task['status'] === TaskStatus::STOPPED
                    || $task['status'] === TaskStatus::ERRORED
                ) {
                    $ts = new TaskResult(
                        $task['status'],
                        $task['outputId'],
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

    public function executeTask(mixed $input): TaskResult {
        $this->initClient();

        $taskId = $this->sendTask($input);

        while (1) {
            $task = $this->getTask($taskId);
            if ($task === null) {
                throw new DtceException('DTCE Server missing  Task record ' . $taskId);
            } else if ($task['status'] === TaskStatus::FINISHED
                || $task['status'] === TaskStatus::STOPPED
                || $task['status'] === TaskStatus::ERRORED
            ) {
                return new TaskResult(
                    $task['status'],
                    $task['outputId'],
                    $this->storage
                );
            }

            System::sleep(0.1);
        }
    }

    protected function sendTask(mixed $input): string {
        $dataId = null;

        if (!is_null($input)) {
            $dataId = Uuid::uuid4()->toString();
            $this->storage->put($dataId, serialize($input));
        }

        $this->client->send(json_encode([
            'cmd' => TaskCommand::ADD_TASK,
            'name' => $this->getTaskName(),
            'inputId' => $dataId
        ]));

        $resp = $this->client->recv();
        $json = json_decode($resp, true);

        if (!isset($json['status'])) {
            throw new DtceException('Could not send task to server ' . $resp);
        }
        if ($json['status'] !== CommandStatus::SUCCESS) {
            throw new DtceException('Server responded error while creating new task ' . $resp);
        }

        return $json['data']['id'];
    }

    public function addTask(mixed $input): string {
        $this->initClient();
        return $this->sendTask($input);
    }

    protected function getTask(string $taskId): ?array {
        $this->client->send(json_encode([
            'cmd' => TaskCommand::GET_TASK,
            'name' => $this->getTaskName(),
            'id' => $taskId
        ]));

        $resp = $this->client->recv();
        $json = json_decode($resp, true);

        if (!isset($json['status'])) {
            throw new DtceException('Could not get task status from server ' . $resp);
        }
        if ($json['status'] !== CommandStatus::SUCCESS) {
            return null;
        }

        return $json['data'];
    }

    public function taskStatus(string $taskId): ?int {
        $this->initClient();
        $task = $this->getTask($taskId);
        return $task ? $task['status'] : TaskStatus::UNKNOWN;
    }

    public function stopTask(string $taskId): void {
        $this->initClient();
        $this->doStopTask($taskId);
    }

    protected function doStopTask(string $taskId): void {
        $this->client->send(json_encode([
            'cmd' => TaskCommand::STOP_TASK,
            'name' => $this->getTaskName(),
            'id' => $taskId
        ]));

        $resp = $this->client->recv();
        $json = json_decode($resp, true);

        if (!isset($json['status'])) {
            throw new DtceException('Could not stop task on server ' . $resp);
        }
    }

}