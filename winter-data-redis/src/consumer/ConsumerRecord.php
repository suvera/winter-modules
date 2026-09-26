<?php
declare(strict_types=1);

namespace dev\winterframework\data\redis\consumer;

class ConsumerRecord {

    public function __construct(
        private string $id,
        private string $stream,
        private array $fields,
        private ?string $group = null,
        private int $deliveryCount = 1
    ) {
    }

    public static function fromStreamEntry(
        string $stream,
        string $id,
        array $fields,
        ?string $group = null,
        int $deliveryCount = 1
    ): self {
        return new self($id, $stream, $fields, $group, $deliveryCount);
    }

    public function getId(): string {
        return $this->id;
    }

    public function getStream(): string {
        return $this->stream;
    }

    public function getFields(): array {
        return $this->fields;
    }

    public function getGroup(): ?string {
        return $this->group;
    }

    public function getDeliveryCount(): int {
        return $this->deliveryCount;
    }

    /**
     * Primary message payload. Producers store it under the "payload" field;
     * falls back to the JSON-encoded full field map for foreign publishers.
     */
    public function getPayload(): string {
        $payload = $this->fields['payload'] ?? null;
        if (is_string($payload)) {
            return $payload;
        }

        return json_encode($this->fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function getValue(): string {
        return $this->getPayload();
    }

    public function getQueueName(): string {
        return $this->stream;
    }

}
