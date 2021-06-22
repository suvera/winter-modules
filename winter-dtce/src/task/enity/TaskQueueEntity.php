<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\enity;

use DateTime;

interface TaskQueueEntity {

    public function getIdColumn(): string;

    public function getProcessorIdColumn(): string;

    public function getOrderByColumn(): string;

    public function getDateCreatedColumn(): string;
    
    public function getTaskNameColumn(): ?string;

    public function getId(): string;

    public function setId(string $id): void;

    public function getTaskName(): string;

    public function setTaskName(string $taskName): void;

    public function getUpdatedOn(): DateTime;

    public function setUpdatedOn(DateTime $updatedOn): void;

    public function getProcessorId(): ?string;

    public function setProcessorId(?string $processorId): void;

    public function getData(): string;

    public function setData(string $data): void;
}