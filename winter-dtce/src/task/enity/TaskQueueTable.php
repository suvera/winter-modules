<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\enity;

use DateTime;
use dev\winterframework\ppa\PpaEntity;
use dev\winterframework\ppa\PpaEntityTrait;
use dev\winterframework\stereotype\ppa\Column;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\util\DateUtil;

#[Table(name: "DTCE_TASK_QUEUE")]
class TaskQueueTable implements PpaEntity, TaskQueueEntity {
    use PpaEntityTrait;

    #[Column(name: "ID", length: 36, id: true)]
    private string $id;

    #[Column(name: "TASK_NAME")]
    private string $taskName;

    #[Column(name: "DATA", length: 255)]
    private string $data;

    #[Column(name: "PROCESSOR_ID", length: 100)]
    private ?string $processorId;

    #[Column(name: "CREATED_ON")]
    private DateTime $createdOn;

    #[Column(name: "UPDATED_ON")]
    private DateTime $updatedOn;

    public function __construct() {
        $this->createdOn = DateUtil::getCurrentDateTime();
        $this->updatedOn = DateUtil::getCurrentDateTime();
    }

    public function getId(): string {
        return $this->id;
    }

    public function setId(string $id): void {
        $this->id = $id;
    }

    public function getTaskName(): string {
        return $this->taskName;
    }

    public function setTaskName(string $taskName): void {
        $this->taskName = $taskName;
    }

    public function getCreatedOn(): DateTime {
        return $this->createdOn;
    }

    public function setCreatedOn(DateTime $createdOn): void {
        $this->createdOn = $createdOn;
    }

    public function getUpdatedOn(): DateTime {
        return $this->updatedOn;
    }

    public function setUpdatedOn(DateTime $updatedOn): void {
        $this->updatedOn = $updatedOn;
    }

    public function getProcessorId(): ?string {
        return $this->processorId;
    }

    public function setProcessorId(?string $processorId): void {
        $this->processorId = $processorId;
    }

    public function getData(): string {
        return $this->data;
    }

    public function setData(string $data): void {
        $this->data = $data;
    }

    public function getIdColumn(): string {
        return 'ID';
    }

    public function getProcessorIdColumn(): string {
        return 'PROCESSOR_ID';
    }

    public function getOrderByColumn(): string {
        return 'CREATED_ON';
    }

}