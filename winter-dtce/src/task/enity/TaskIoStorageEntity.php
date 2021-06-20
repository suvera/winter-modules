<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\enity;

interface TaskIoStorageEntity {
    public function getIdColumn(): string;

    public function getDateCreatedColumn(): string;

    public function setId(string $id): void;

    public function getId(): ?string;

    public function setData(string $data): void;

    public function getData(): ?string;

    public function setTaskName(string $taskName): void;

    public function getTaskName(): ?string;
}