<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\dtce\task\TaskObject;

interface TaskQueueHandler {

    public function __construct(ApplicationContext $ctx, array $config, array $dtceConfig);

    public function taskName(): string;

    public function pop(): ?TaskObject;

    public function push(TaskObject $task): void;

    public function get(string $taskId): ?TaskObject;

    public function delete(string $taskId): void;

    public function taskStatus(string $taskId, int $taskStatus): void;

    public function taskOutput(string $taskId, string $dataId): void;
}