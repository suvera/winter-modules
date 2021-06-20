<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\storage;

use dev\winterframework\core\context\ApplicationContext;
use dev\winterframework\io\stream\InputStream;

interface TaskIOStorageHandler {
    const GC_TIME = 14400;
    const GC_TIME_DT = 'P4H';

    public function __construct(ApplicationContext $ctx, array $taskDef);

    public function getInputStream(string|int $dataId): InputStream;

    public function put(string|int $dataId, string $data): void;
    
    public function delete(string|int $dataId): void;
}