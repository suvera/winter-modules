<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\server;

use Co\System;
use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;
use dev\winterframework\dtce\task\storage\TaskQueueHandler;
use dev\winterframework\dtce\task\TaskCommand;
use dev\winterframework\dtce\task\TaskObject;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\dtce\task\worker\TaskWorker;
use dev\winterframework\io\stream\FileOutputStream;
use dev\winterframework\io\stream\OutputStream;
use dev\winterframework\io\stream\StringOutputStream;
use dev\winterframework\type\TypeAssert;
use dev\winterframework\util\log\Wlf4p;
use Ramsey\Uuid\Uuid;
use Swoole\Server;
use Swoole\Table;
use Throwable;

class TaskServer {
    use Wlf4p;

    protected Server $server;
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
        protected array $config
    ) {
        $this->checkConfig();

        $this->server = new Server(
            "0.0.0.0",
            $this->config['server.port'],
            SWOOLE_BASE,
            SWOOLE_SOCK_TCP
        );

        $this->workers = new Table(1000);
        $this->workers->column('id', Table::TYPE_STRING, 36);
        $this->workers->column('name', Table::TYPE_STRING, 128);
        $this->workers->create();
    }

    public function start(): void {
        $serverConfig = $this->config['server.settings'][0] ?? [];
        $tasks = $this->config['tasks'] ?? [];

        $totalWorkers = 0;
        $serverConfig['worker_num'] = $serverConfig['worker_num'] ?? 1;
        $curWorkerId = $serverConfig['worker_num'];
        foreach ($tasks as $task) {
            if (strlen($task['name']) > 128) {
                throw new DtceException('tasks.name must not exceed 128 characters, but ' . $task['name']);
            }
            $storageHandler = $task['storage.hHandler'] ?? '--NONE--';
            $queueHandler = $task['queue.'] ?? '--NONE--';
            $workerClass = $task['worker.class'];

            TypeAssert::objectOfIsA($storageHandler, TaskIOStorageHandler::class,
                'Task Storage Handler must implement ' . TaskIOStorageHandler::class);

            TypeAssert::objectOfIsA($workerClass, TaskWorker::class,
                'Task Worker must implement ' . TaskWorker::class);

            TypeAssert::objectOfIsA($queueHandler, TaskQueueHandler::class,
                'Task queue handler must implement ' . TaskQueueHandler::class);

            $numWorkers = $task['worker.total'] ?? 1;

            $this->taskWorkers[$task['name']] = $numWorkers;
            $this->taskQueues[$task['name']] = new $queueHandler($this->ctx, $task, $this->config);
            $this->storage[$task['name']] = new $storageHandler($this->ctx, $task);

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

        $serverConfig['task_worker_num'] = $totalWorkers ?: 0;

        $this->server->set($serverConfig);

        if ($serverConfig['task_worker_num'] > 0) {
            $this->onWorkerStart();
            $this->onWorkerStop();
            $this->onWorkerError();
            $this->onTask();
        }
        $this->onReceive();

        $this->server->start();
    }

    protected function createOutput(TaskObject $task): OutputStream {
        if ($this->config['server.temp.store'] == 'file') {
            return new FileOutputStream(
                $this->config['server.temp.path'] . DIRECTORY_SEPARATOR . 'dtce-' . $task->getId() . '.out'
            );
        }

        return new StringOutputStream();
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
        if ($this->config['server.temp.store'] == 'file') {
            $this->config['server.temp.path'] = rtrim($this->config['server.temp.path'], '/');
            $written = file_put_contents(
                $this->config['server.temp.path'] . DIRECTORY_SEPARATOR . 'dtce-temp.txt',
                'DTCE'
            );
            if ($written === false) {
                throw new DtceException('Could not write to temp folder ' . $this->config['server.temp.path']);
            }
        }
    }

    /**
     * Start workers
     */
    protected function onWorkerStart(): void {
        $this->server->on('WorkerStart', function (Server $server, int $workerId) {
            if ($workerId < $server->setting['worker_num']) {
                return;
            }

            $taskName = $this->workerTaskMap[$workerId];
            $worker = $this->taskWorkerMap[$taskName][$workerId];
            $queue = $this->taskQueues[$taskName];

            while (1) {
                $task = $queue->pop();
                if ($task) {
                    $this->workers[$workerId] = ['id' => $task->getId(), 'name' => $task->getName()];

                    try {
                        $os = $this->createOutput($task);
                        $worker->work($this->createInput($task), $os);
                        $this->storeOutput($task, $os);
                        $this->workerSuccess($workerId);
                    } catch (Throwable $e) {
                        self::logException($e);
                        $this->workerFailed($workerId);
                    }
                }

                System::sleep(0.2);
            }
        });

    }

    /**
     * Worker Stopped
     */
    protected function onWorkerStop(): void {
        $this->server->on('WorkerStop', function (Server $server, int $workerId) {
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

        $this->server->on('WorkerError', function (Server $server, int $workerId) {
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
        $this->workers->del($workerId);
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
        $this->workers->del($workerId);
    }

    protected function onReceive(): void {
        $this->server->on('receive', function (Server $server, $fd, $reactorId, $data) {
            $json = json_decode($data, true);
            if (!is_array($json)) {
                self::logError('Invalid Task Command ' . $data);
                $server->send($fd, json_encode([
                    'status' => CommandStatus::ERROR,
                    'error' => 'Invalid Task Command'
                ]));
                return;
            }

            switch ($json['cmd']) {
                case TaskCommand::ADD_TASK;
                    self::logInfo('Adding new task ' . $data);
                    $task = TaskObject::fromArray($json);
                    try {
                        $this->taskQueues[$task->getName()]->push($task);
                        $server->send($fd, json_encode([
                            'status' => CommandStatus::SUCCESS,
                            'data' => $task
                        ]));
                    } catch (Throwable $e) {
                        self::logException($e);
                        $server->send($fd, json_encode([
                            'status' => CommandStatus::ERROR,
                            'error' => $e->getMessage()
                        ]));
                    }
                    break;

                case TaskCommand::STOP_TASK;
                    self::logInfo('Stopping task ' . $data);
                    foreach ($this->workers as $workerId => $assign) {
                        if ($assign['id'] == $json['id']) {
                            $server->stop(intval($workerId));
                            break;
                        }
                    }
                    $this->taskQueues[$json['name']]->taskStatus($json['id'], TaskStatus::STOPPED);
                    $server->send($fd, json_encode([
                        'status' => CommandStatus::SUCCESS,
                        'data' => 'ACK'
                    ]));
                    break;

                case TaskCommand::GET_TASK;
                    self::logInfo('Read task ' . $data);
                    $task = $this->taskQueues[$json['name']]->get($json['id']);
                    if (!$task) {
                        $server->send($fd, json_encode([
                            'status' => CommandStatus::ERROR,
                            'error' => 'No task exist with given id ' . $json['id']
                        ]));
                    } else {
                        $server->send($fd, json_encode([
                            'status' => CommandStatus::SUCCESS,
                            'data' => $task
                        ]));
                    }
                    break;

                default:
                    self::logError('Unknown Task Command ' . $data);
                    $server->send($fd, json_encode([
                        'status' => CommandStatus::ERROR,
                        'error' => 'Unknown Task Command'
                    ]));
                    break;
            }
        });
    }

    protected function onTask(): void {
        $this->server->on('Task', function (Server $server, $threadId, $reactorId, $data) {
        });
    }

    protected function storeOutput(TaskObject $task, OutputStream $os): void {
        $is = $os->getInputStream();
        $out = $is->read();
        if (!strlen($out)) {
            return;
        }
        $dataId = Uuid::uuid4()->toString();
        $this->storage[$task->getName()]->put($dataId, $out);
        $this->taskQueues[$task->getName()]->taskOutput($task->getId(), $dataId);
    }
}