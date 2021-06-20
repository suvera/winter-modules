<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\exception\DtceException;
use dev\winterframework\dtce\task\server\TaskServer;

class TaskExecutionServiceFactory {

    /**
     * @var TaskExecutionService[]
     */
    protected array $taskExServices = [];

    public function __construct(
        protected ApplicationContext $ctx,
        protected array $config,
        protected TaskServer $taskServer
    ) {

        $tasks = $this->config['tasks'] ?? [];
        foreach ($tasks as $task) {
            $this->taskExServices[$task['name']] = new TaskExecutionServiceImpl(
                $this->ctx,
                $this->taskServer,
                $task,
                $this->config
            );
        }
    }

    public function executionService(string $taskName): TaskExecutionService {
        if (!isset($this->taskExServices[$taskName])) {
            throw new DtceException('Could not find task with name ' . $taskName);
        }

        return $this->taskExServices[$taskName];
    }
}