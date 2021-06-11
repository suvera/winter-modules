<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\io;

use dev\winterframework\io\stream\InputStream;

interface TaskStorageHandler {

    public function getInputStream(string|int $dataId): InputStream;

    public function put(string|int $dataId, string $data): void;
}