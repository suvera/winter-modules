<?php
declare(strict_types=1);

namespace dev\winterframework\sqs\consumer;

use Aws\Result;

class ConsumerRecord {

    public function __construct(
        private string $messageId,
        private string $receiptHandle,
        private string $body,
        private ?array $attributes = null,
        private ?array $messageAttributes = null,
        private ?string $queueName = null,
        private ?string $md5OfBody = null
    ) {
    }

    public static function fromResult(Result|array $message, ?string $queueName = null): self {
        return new self(
            strval($message['MessageId'] ?? ''),
            strval($message['ReceiptHandle'] ?? ''),
            strval($message['Body'] ?? ''),
            isset($message['Attributes']) ? $message['Attributes'] : null,
            isset($message['MessageAttributes']) ? $message['MessageAttributes'] : null,
            $queueName,
            isset($message['MD5OfBody']) ? strval($message['MD5OfBody']) : null
        );
    }

    public function getMessageId(): string {
        return $this->messageId;
    }

    public function getReceiptHandle(): string {
        return $this->receiptHandle;
    }

    public function getBody(): string {
        return $this->body;
    }

    public function getAttributes(): ?array {
        return $this->attributes;
    }

    public function getMessageAttributes(): ?array {
        return $this->messageAttributes;
    }

    public function getQueueName(): ?string {
        return $this->queueName;
    }

    public function getMd5OfBody(): ?string {
        return $this->md5OfBody;
    }

    public function getValue(): string {
        return $this->body;
    }

}
