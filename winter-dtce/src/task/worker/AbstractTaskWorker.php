<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\worker;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;

abstract class AbstractTaskWorker implements TaskWorker {

    public function __construct(
        protected ApplicationContext $ctx,
        protected int $workerId,
        protected array $taskDef,
        protected TaskIOStorageHandler $storageHandler
    ) {
    }

}