<?php
declare(strict_types=1);


namespace dev\winterframework\dtce\task;

use dev\winterframework\dtce\task\io\TaskInputOutput;

interface TaskExecutionService {

    public function executeTask(string $taskName, TaskExecutionContext $ctx): TaskInputOutput;

    public function terminateTask(string $taskName, TaskExecutionContext $ctx): void;

}