<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

use dev\winterframework\dtce\task\Job;
use dev\winterframework\dtce\task\JobResult;
use dev\winterframework\dtce\task\TaskIds;
use dev\winterframework\dtce\task\TaskResult;

interface TaskExecutionService {

    public function getTaskName(): string;

    /**
     * Execute a Job that may contains many tasks
     *  - Blocking call
     */
    public function executeJob(Job $job): JobResult;

    /**
     * Initiate Job execution, and return TaskIds of all tasks of job
     *  - Non-Blocking call, instantly return task Ids
     */
    public function addJob(Job $job): TaskIds;

    public function newJob(): Job;

    /**
     * Execute a Task
     *  - Blocking call
     */
    public function executeTask(mixed $input): TaskResult;

    /**
     * Initiate the task execution and returns TASK ID
     *  - Non blocking, instantly return the taskId
     */
    public function addTask(mixed $input): string;

    /**
     * Check task status
     *  - check the task status,   see CONSTANT defined in the class 'TaskStatus'
     */
    public function taskStatus(string $taskId): ?int;

    /**
     * Stop any running task.
     */
    public function stopTask(string $taskId): void;
}