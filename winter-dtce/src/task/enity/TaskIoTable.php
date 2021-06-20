<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task\enity;

use DateTime;
use dev\winterframework\pdbc\types\Blob;
use dev\winterframework\ppa\PpaEntity;
use dev\winterframework\ppa\PpaEntityTrait;
use dev\winterframework\stereotype\ppa\Column;
use dev\winterframework\stereotype\ppa\Table;
use dev\winterframework\util\DateUtil;

#[Table(name: "DTCE_IO_STORE")]
class TaskIoTable implements PpaEntity, TaskIoStorageEntity {
    use PpaEntityTrait;

    #[Column(name: "ID", length: 36, id: true)]
    private string $id;

    #[Column(name: "TASK_NAME")]
    private string $taskName;

    #[Column(name: "DATA")]
    private Blob $blobData;

    #[Column(name: "CREATED_ON")]
    private DateTime $createdOn;

    public function __construct() {
        $this->createdOn = DateUtil::getCurrentDateTime();
    }

    public function getId(): ?string {
        return $this->id;
    }

    public function setId(string $id): void {
        $this->id = $id;
    }

    public function getTaskName(): ?string {
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

    public function getBlobData(): Blob {
        return $this->blobData;
    }

    public function setBlobData(Blob $blobData): void {
        $this->blobData = $blobData;
    }

    public function getIdColumn(): string {
        return 'ID';
    }

    public function setData(string $data): void {
        $this->setBlobData(Blob::valueOf($data));
    }

    public function getData(): ?string {
        if (isset($this->blobData)) {
            return $this->blobData->getString();
        }
        return null;
    }

    public function getDateCreatedColumn(): string {
        return 'CREATED_ON';
    }

}