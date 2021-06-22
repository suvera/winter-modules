<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

use dev\winterframework\dtce\task\storage\TaskIOStorageHandler;
use dev\winterframework\dtce\task\worker\TaskOutput;

class TaskResult {
    protected ?TaskOutput $data = null;
    protected bool $dataSet = false;

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

    public function getResult(): ?TaskOutput {
        $this->dataSet = true;
        if (!$this->dataId) {
            return $this->data;
        }

        $stream = $this->storage->getInputStream($this->dataId);
        $data = $stream->read();

        /** @var TaskOutput|false $obj */
        $obj = unserialize($data);

        if ($obj !== false) {
            $this->data = $obj;
        }

        return $this->data;
    }

}