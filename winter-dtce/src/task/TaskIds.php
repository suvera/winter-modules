<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

class TaskIds {

    /**
     * @var string[]
     */
    protected array $ids;

    public function addResult(mixed $index, string $id): void {
        $this->ids[$index] = $id;
    }

    /**
     * @return string[]
     */
    public function getIds(): array {
        return $this->ids;
    }
}