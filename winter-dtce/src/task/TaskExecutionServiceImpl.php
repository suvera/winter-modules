<?php
declare(strict_types=1);


namespace dev\winterframework\dtce\task;

use dev\winterframework\dtce\task\io\TaskInputOutput;
use dev\winterframework\stereotype\Service;

#[Service]
class TaskExecutionServiceImpl implements TaskExecutionService {

    public function executeTask(string $taskName, TaskExecutionContext $ctx): TaskInputOutput {
        // TODO: Implement executeTask() method.
    }

    public function terminateTask(string $taskName, TaskExecutionContext $ctx): void {
        // TODO: Implement terminateTask() method.
    }

}