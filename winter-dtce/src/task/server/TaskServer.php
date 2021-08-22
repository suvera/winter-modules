<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\server;

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
use dev\winterframework\exception\WinterException;
use dev\winterframework\io\shm\ShmTable;
use dev\winterframework\reflection\ref\RefKlass;
use dev\winterframework\reflection\ReflectionUtil;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;
use Swoole\Server;
use Throwable;

class TaskServer {
    use Wlf4p;

    protected ShmTable $workers;
    protected array $taskWorkers = [];
    protected array $workerTaskMap = [];
    protected array $workerTaskMapFlip = [];

    /**
     * @var TaskWorker[][]
     */
    protected array $taskWorkerMap = [];

    /**
     * @var TaskQueueHandler[]
     */
    protected array $taskQueues = [];

    /**
     * @var TaskIOStorageHandler[]
     */
    protected array $storage;

    protected int $totalWorkers = 0;

    public function __construct(
        protected ApplicationContext $ctx,
        protected WinterServer $wServer,
        protected array $config
    ) {
        $this->checkConfig();

        $this->workers = new ShmTable(
            1000, [
                ['id', ShmTable::TYPE_STRING, 48],
                ['name', ShmTable::TYPE_STRING, 128]
            ]
        );

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

            $len = $numWorkers + $curWorkerId;
            for (; $curWorkerId < $len; $curWorkerId++) {
                if (!isset($this->workerTaskMapFlip[$task['name']])) {
                    $this->workerTaskMapFlip[$task['name']] = [];
                }
                $this->workerTaskMapFlip[$task['name']][$curWorkerId] = $curWorkerId;
                $this->workerTaskMap[$curWorkerId] = [
                    $task['name'],
                    $workerClass,
                    $queueHandler,
                    $storageHandler,
                    $task
                ];
            }
            $totalWorkers += $numWorkers;
        }

        if ($totalWorkers > 0) {
            $this->wServer->addServerArg('task_worker_num', $totalWorkers);
        }

        $this->totalWorkers = $totalWorkers;
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

    protected function initTaskQueueAndStore(string $taskName): void {
        if (isset($this->taskQueues[$taskName])) {
            return;
        }

        $taskWorkerIds = $this->workerTaskMapFlip[$taskName];
        $workerId = array_key_first($taskWorkerIds);
        /** @noinspection PhpUnusedLocalVariableInspection */
        list($taskName, $workerClass, $queueHandler, $storageHandler, $taskDef) = $this->workerTaskMap[$workerId];

        try {
            $store = ReflectionUtil::createAutoWiredObject(
                $this->ctx,
                new RefKlass($storageHandler),
                $this->ctx,
                $taskDef
            );
            $queue = ReflectionUtil::createAutoWiredObject(
                $this->ctx,
                new RefKlass($queueHandler),
                $this->ctx,
                $store,
                $taskDef,
                $this->config
            );
        } catch (Throwable $e) {
            $this->wServer->shutdown('Could not create a DTCE Store or Queue objects', $e);
            throw new WinterException('Could not create a DTCE Store or Queue objects');
        }
        $this->taskQueues[$taskName] = $queue;
        $this->storage[$taskName] = $store;
    }

    /**
     * Start workers
     */
    protected function onWorkerStart(): void {
        $this->wServer->addEventCallback('WorkerStart', function (Server $server, int $workerId) {
            if ($workerId < $server->setting['worker_num']) {
                return;
            }
            sleep(5);

            /** @noinspection PhpUnusedLocalVariableInspection */
            list($taskName, $workerClass, $queueHandler, $storageHandler, $taskDef) = $this->workerTaskMap[$workerId];

            $this->initTaskQueueAndStore($taskName);

            try {
                $worker = ReflectionUtil::createAutoWiredObject(
                    $this->ctx,
                    new RefKlass($workerClass),
                    $this->ctx,
                    $workerId,
                    $taskDef,
                    $this->storage[$taskName]
                );
            } catch (Throwable $e) {
                $this->wServer->shutdown('Could not create a DTCE Worker', $e);
                throw new WinterException('Could not create a DTCE Worker');
            }
            $this->taskWorkerMap[$taskName][$workerId] = $worker;
            $queue = $this->taskQueues[$taskName];

            self::logInfo('Worker started listening Queue');
            $sleepMs = 200000;

            while (1) {
                $task = $queue->pop();
                if ($task) {
                    self::logInfo("New Task Grabbed by worker($workerId) " . $task->getId());
                    $this->workers[$workerId] = ['id' => $task->getId(), 'name' => $task->getName()];
                    $sleepMs = 200000;
                    try {
                        $output = $worker->work($this->createInput($task));
                        $this->storeOutput($task, $output);
                        $this->workerSuccess($workerId);
                    } catch (Throwable $e) {
                        self::logException($e);
                        $this->workerFailed($workerId);
                    }
                } else {
                    if ($sleepMs >= 20000000) {
                        $sleepMs = 20000000;
                    } else {
                        $sleepMs += 200000;
                    }
                }

                //System::sleep(0.2);
                usleep($sleepMs);
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
                $this->initTaskQueueAndStore($task['name']);
                $this->taskQueues[$task['name']]->taskStatus($task['id'], TaskStatus::ERRORED);
            }
        }
        $this->workers->delete('' . $workerId);
    }

    protected function workerSuccess(int $workerId): void {
        if (isset($this->workers[$workerId])) {
            $task = $this->workers[$workerId];
            if ($task && $task['id']) {
                self::logInfo('[DTCE] task worker finished work, so task '
                    . $task['id'] . ' marked Success');
                $this->initTaskQueueAndStore($task['name']);
                $this->taskQueues[$task['name']]->taskStatus($task['id'], TaskStatus::FINISHED);
            }
        }
        $this->workers->delete('' . $workerId);
    }

    public function addTask(TaskObject $task): bool {
        $this->initTaskQueueAndStore($task->getName());
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
        $this->initTaskQueueAndStore($taskName);
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
        $this->initTaskQueueAndStore($taskName);
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
        $this->initTaskQueueAndStore($task->getName());
        $out = serialize($output);

        $dataId = 'out-' . Uuid::uuid4()->toString();
        $this->storage[$task->getName()]->put($dataId, $out);
        $this->taskQueues[$task->getName()]->taskOutput($task->getId(), $dataId);
    }

    public function getTotalWorkers(): int {
        return $this->totalWorkers;
    }

    public function getTaskTotalWorkers(string $taskName): int {
        return $this->taskWorkers[$taskName] ?? 0;
    }

    public function getTaskStorageHandler(string $taskName): ?TaskIOStorageHandler {
        return $this->storage[$taskName] ?? null;
    }

    public function getTaskQueueHandler(string $taskName): ?TaskQueueHandler {
        $this->initTaskQueueAndStore($taskName);
        return $this->taskQueues[$taskName] ?? null;
    }

    public function getTaskNameForWorkerId(int $workerId): string {
        return $this->workerTaskMap[$workerId][0] ?? '';
    }
}