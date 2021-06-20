<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;

interface TaskWorker {

    public function __construct(
        ApplicationContext $ctx,
        int $workerId,
        array $taskDef,
        TaskIOStorageHandler $storage
    );

    public function work(mixed $input): TaskOutput;
}