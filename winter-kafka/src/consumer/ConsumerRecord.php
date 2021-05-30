<?php

namespace dev\winterframework\kafka\consumer;

use RdKafka\Message;

class ConsumerRecord {

    private mixed $timestamp;
    private ?array $headers;

    public function __construct(
        private mixed $value,
        private string $topic,
        private int $partition = -1,
        private int $offset = -1,
        private ?string $groupName = null
    ) {
        $this->timestamp = time();
    }

    public static function fromMessage(Message $message, string $groupName = null): self {
        $obj = new self(
            $message->payload,
            $message->topic_name,
            $message->partition,
            $message->offset,
            $groupName
        );

        $obj->headers = $message->headers;
        $obj->timestamp = $message->timestamp;

        return $obj;
    }

    /**
     * @return string|null
     */
    public function getGroupName(): ?string {
        return $this->groupName;
    }

    /**
     * @return string
     */
    public function getTopic(): string {
        return $this->topic;
    }

    /**
     * @return int
     */
    public function getPartition(): int {
        return $this->partition;
    }

    /**
     * @return int
     */
    public function getOffset(): int {
        return $this->offset;
    }

    /**
     * @return mixed
     */
    public function getValue(): mixed {
        return $this->value;
    }

    /**
     * @return int|mixed
     */
    public function getTimestamp(): mixed {
        return $this->timestamp;
    }

    /**
     * @return array|null
     */
    public function getHeaders(): ?array {
        return $this->headers;
    }

}