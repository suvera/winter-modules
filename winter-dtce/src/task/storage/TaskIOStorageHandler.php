<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\stream\InputStream;

interface TaskIOStorageHandler {

    public function __construct(ApplicationContext $ctx, array $taskDef);

    public function getInputStream(string|int $dataId): InputStream;

    public function put(string|int $dataId, string $data): void;
}