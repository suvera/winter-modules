<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\task\io\TaskInputOutput;

interface TaskExecutionHandler {

    public function __construct(ApplicationContext $appCtx, array $taskConfig);

    public function run(TaskExecutionContext $ctx): TaskInputOutput;

}