<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\service;

use dev\winterframework\dtce\task\Job;
use dev\winterframework\dtce\task\JobResult;
use dev\winterframework\dtce\task\TaskResult;

interface TaskExecutionService {

    public function getTaskName(): string;

    /**
     * Execute a Job that may contains many tasks
     *  - Blocking call
     */
    public function executeJob(Job $job): JobResult;

    /**
     * Execute a Task
     *  - Blocking call
     */
    public function executeTask(mixed $input): TaskResult;

    /**
     * Initiate the task execution and returns TASK ID
     *  - Non blocking, poll with taskStatus() to check task status
     */
    public function addTask(mixed $input): string;

    /**
     * Check task status
     *  - check status constant in the class 'TaskStatus'
     */
    public function taskStatus(string $taskId): ?int;

    /**
     * Stop any running task.
     */
    public function stopTask(string $taskId): void;
}