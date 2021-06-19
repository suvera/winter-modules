<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

class JobResult {

    /**
     * @var TaskResult[]
     */
    protected array $results;

    public function addResult(mixed $index, TaskResult $taskResult): void {
        $this->results[$index] = $taskResult;
    }

    /**
     * @return TaskResult[]
     */
    public function getResults(): array {
        return $this->results;
    }

}