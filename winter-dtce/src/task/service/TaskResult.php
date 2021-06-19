<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;
use dev\winterframework\dtce\task\TaskStatus;
use dev\winterframework\io\stream\InputStream;

class TaskResult {

    public function __construct(
        protected int $status,
        protected string|int $dataId,
        protected TaskIOStorageHandler $storage
    ) {
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function isSuccess(): bool {
        return $this->status == TaskStatus::FINISHED;
    }

    public function getResult(): ?InputStream {
        if (!$this->dataId) {
            return null;
        }
        return $this->storage->getInputStream($this->dataId);
    }

}