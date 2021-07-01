<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\server;

use Co\System;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\core\context\WinterServer;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;
use dev\winterframework\dtce\task\storage\TaskQueueHandler;
use dev\winterframework\dtce\task\TaskObject;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\dtce\task\worker\output\NullOutput;
use dev\winterframework\dtce\task\worker\TaskOutput;
use dev\winterframework\dtce\task\worker\TaskWorker;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;
use Swoole\Server;
use Swoole\Table;
use Throwable;

class TaskServer {
    use Wlf4p;

    protected Table $workers;
    protected array $taskWorkers = [];
    protected array $workerTaskMap = [];

    /**
     * @var TaskWorker[][]
     */
    protected array $taskWorkerMap = [];

    /**
     * @var TaskQueueHandler[]
     */
    protected array $taskQueues;

    /**
     * @var TaskIOStorageHandler[]
     */
    protected array $storage;

    public function __construct(
        protected ApplicationContext $ctx,
        protected WinterServer $wServer,
        protected array $config
    ) {
        $this->checkConfig();

        $this->workers = new Table(1000);
        $this->workers->column('id', Table::TYPE_STRING, 48);
        $this->workers->column('name', Table::TYPE_STRING, 128);
        $this->workers->create();

        $this->init();
    }

    public function start(): void {
    }

    protected function init(): void {
        $tasks = $this->config['tasks'] ?? [];

        $totalWorkers = 0;
        $curWorkerId = $this->wServer->getServerArg('worker_num') ?? 0;
        foreach ($tasks as $task) {
            if (strlen($task['name']) > 128) {
                throw new DtceException('tasks.name must not exceed 128 characters, but ' . $task['name']);
            }
            $storageHandler = $task['storage.handler'] ?? '--NONE--';
            $queueHandler = $task['queue.handler'] ?? '--NONE--';
            $workerClass = $task['worker.class'];

            TypeAssert::objectOfIsA($storageHandler, TaskIOStorageHandler::class,
                'Task Storage Handler must implement ' . TaskIOStorageHandler::class);

            TypeAssert::objectOfIsA($workerClass, TaskWorker::class,
                'Task Worker must implement ' . TaskWorker::class);

            TypeAssert::objectOfIsA($queueHandler, TaskQueueHandler::class,
                'Task queue handler must implement ' . TaskQueueHandler::class);

            $numWorkers = $task['worker.total'] ?? 1;

            $this->taskWorkers[$task['name']] = $numWorkers;
            $this->storage[$task['name']] = new $storageHandler($this->ctx, $task);

            $this->taskQueues[$task['name']] = new $queueHandler(
                $this->ctx,
                $this->storage[$task['name']],
                $task,
                $this->config
            );


            $len = $numWorkers + $curWorkerId;
            for (; $curWorkerId < $len; $curWorkerId++) {
                $this->taskWorkerMap[$task['name']][$curWorkerId] = new $workerClass(
                    $this->ctx,
                    $curWorkerId,
                    $task,
                    $this->storage[$task['name']]
                );
                $this->workerTaskMap[$curWorkerId] = $task['name'];
            }
            $totalWorkers += $numWorkers;
        }

        if ($totalWorkers > 0) {
            $this->wServer->addServerArg('task_worker_num', $totalWorkers);
        }

        if ($totalWorkers > 0) {
            $this->onWorkerStart();
            $this->onWorkerStop();
            $this->onWorkerError();
            $this->onTask();
        }
    }

    protected function createInput(TaskObject $task): mixed {
        if (!$task->getInputId()) {
            return null;
        }
        $is = $this->storage[$task->getName()]->getInputStream($task->getInputId());

        if (is_null($is)) {
            return null;
        }

        return unserialize($is->read());
    }

    protected function checkConfig(): void {
    }

    /**
     * Start workers
     */
    protected function onWorkerStart(): void {
        $this->wServer->addEventCallback('WorkerStart', function (Server $server, int $workerId) {
            if ($workerId < $server->setting['worker_num']) {
                return;
            }

            $taskName = $this->workerTaskMap[$workerId];
            $worker = $this->taskWorkerMap[$taskName][$workerId];
            $queue = $this->taskQueues[$taskName];

            sleep(5);
            self::logInfo('Worker started listening Queue');

            while (1) {
                $task = $queue->pop();
                if ($task) {
                    self::logInfo("New Task Grabbed by worker($workerId) " . $task->getId());
                    $this->workers[$workerId] = ['id' => $task->getId(), 'name' => $task->getName()];

                    try {
                        $output = $worker->work($this->createInput($task));
                        $this->storeOutput($task, $output);
                        $this->workerSuccess($workerId);
                    } catch (Throwable $e) {
                        self::logException($e);
                        $this->workerFailed($workerId);
                    }
                }
                //else {
                //self::logInfo("* NO Task for worker($workerId) ");
                //}

                //System::sleep(0.2);
                usleep(200000);
            }
        });

    }

    /**
     * Worker Stopped
     */
    protected function onWorkerStop(): void {
        $this->wServer->addEventCallback('WorkerStop', function (Server $server, int $workerId) {
            if ($workerId < $server->setting['worker_num']) {
                return;
            }
            $this->workerFailed($workerId);
        });
    }

    /**
     * Worker Failed with an error/exception
     */
    protected function onWorkerError(): void {

        $this->wServer->addEventCallback('WorkerError', function (Server $server, int $workerId) {
            if ($workerId < $server->setting['worker_num']) {
                return;
            }
            $this->workerFailed($workerId);
        });
    }

    protected function workerFailed(int $workerId): void {
        if (isset($this->workers[$workerId])) {
            $task = $this->workers[$workerId];
            if ($task && $task['id']) {
                self::logError('[DTCE] task worker closed unexpectedly, so task '
                    . $task['id'] . ' marked errored');
                $this->taskQueues[$task['name']]->taskStatus($task['id'], TaskStatus::ERRORED);
            }
        }
        $this->workers->del('' . $workerId);
    }

    protected function workerSuccess(int $workerId): void {
        if (isset($this->workers[$workerId])) {
            $task = $this->workers[$workerId];
            if ($task && $task['id']) {
                self::logError('[DTCE] task worker finished work, so task '
                    . $task['id'] . ' marked Success');
                $this->taskQueues[$task['name']]->taskStatus($task['id'], TaskStatus::FINISHED);
            }
        }
        $this->workers->del('' . $workerId);
    }

    public function addTask(TaskObject $task): bool {
        try {
            $this->taskQueues[$task->getName()]->push($task);
        } catch (Throwable $e) {
            self::logException($e);
            return false;
        }
        return true;
    }

    public function stopTask(string $taskName, string $taskId): bool {
        self::logInfo('Stopping task ' . $taskId);
        foreach ($this->workers as $workerId => $assign) {
            if ($assign['id'] == $taskId) {
                $this->wServer->getServer()->stop(intval($workerId));
                break;
            }
        }
        $this->taskQueues[$taskName]->taskStatus($taskId, TaskStatus::STOPPED);
        return true;
    }

    public function getTask(string $taskName, string $taskId): ?TaskObject {
        $task = $this->taskQueues[$taskName]->get($taskId);
        if (!$task) {
            return null;
        }
        return $task;
    }

    protected function onTask(): void {
        $this->wServer->addEventCallback('Task', function (Server $server, $threadId, $reactorId, $data) {
        });
    }

    protected function storeOutput(TaskObject $task, TaskOutput $output): void {
        if ($output instanceof NullOutput) {
            return;
        }
        $out = serialize($output);

        $dataId = 'out-' . Uuid::uuid4()->toString();
        $this->storage[$task->getName()]->put($dataId, $out);
        $this->taskQueues[$task->getName()]->taskOutput($task->getId(), $dataId);
    }
}