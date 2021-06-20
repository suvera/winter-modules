<?php
declare(strict_types=1);

namespace dev\winterframework\dtce\task;

use JsonSerializable;
use Ramsey\Uuid\Uuid;
use Swoole\Table\Row;

class TaskObject implements JsonSerializable {

    protected string $name;
    protected string $id = '';
    protected int $status;
    protected ?string $inputId = null;
    protected ?string $outputId = null;
    protected int $createdOn;

    public function jsonSerialize(): array {
        return [
            'name' => $this->name,
            'id' => $this->id,
            'status' => $this->status,
            'inputId' => $this->inputId,
            'outputId' => $this->outputId,
            'createdOn' => $this->createdOn,
        ];
    }

    public static function fromArray(array|Row $data): self {
        $task = new TaskObject();

        $task->name = $data['name'];
        $task->status = $data['status'] ?? TaskStatus::QUEUED;
        $task->inputId = $data['inputId'] ?? null;
        $task->outputId = $data['outputId'] ?? null;
        $task->createdOn = $data['createdOn'] ?? time();
        $task->id = $data['id'] ?? Uuid::uuid4()->toString();

        return $task;
    }

    /**
     * @return string
     */
    public function getName(): string {
        return $this->name;
    }

    /**
     * @param string $name
     */
    public function setName(string $name): void {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getId(): string {
        return $this->id;
    }

    /**
     * @param string $id
     */
    public function setId(string $id): void {
        $this->id = $id;
    }

    /**
     * @return int
     */
    public function getStatus(): int {
        return $this->status;
    }

    /**
     * @param int $status
     */
    public function setStatus(int $status): void {
        $this->status = $status;
    }

    /**
     * @return string|null
     */
    public function getInputId(): ?string {
        return $this->inputId;
    }

    /**
     * @param string|null $inputId
     */
    public function setInputId(?string $inputId): void {
        $this->inputId = $inputId;
    }

    /**
     * @return string|null
     */
    public function getOutputId(): ?string {
        return $this->outputId;
    }

    /**
     * @param string|null $outputId
     */
    public function setOutputId(?string $outputId): void {
        $this->outputId = $outputId;
    }

    /**
     * @return int
     */
    public function getCreatedOn(): int {
        return $this->createdOn;
    }

    /**
     * @param int $createdOn
     */
    public function setCreatedOn(int $createdOn): void {
        $this->createdOn = $createdOn;
    }

}