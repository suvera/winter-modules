<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

class Job {

    protected array $tasks = [];

    public function __construct(
        protected string $taskName
    ) {
    }

    public function addTasks(mixed ...$input): void {
        foreach ($input as $task) {
            $this->tasks[] = $task;
        }
    }

    /**
     * @param array $tasks
     */
    public function setTasks(array $tasks): void {
        $this->tasks = $tasks;
    }

    /**
     * @return array
     */
    public function getTasks(): array {
        return $this->tasks;
    }

    /**
     * @return string
     */
    public function getTaskName(): string {
        return $this->taskName;
    }

}