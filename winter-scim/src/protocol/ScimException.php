<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use RuntimeException;

/**
 * A SCIM error (RFC 7644 §3.12). {@see \dev\winterframework\scim\server\ScimEndpoint}
 * turns it into the SCIM error body itself, so it never reaches the app's
 * error controller.
 */
final class ScimException extends RuntimeException {
    public const string SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:Error';

    public function __construct(
        public readonly int $status,
        public readonly ?ScimErrorType $scimType,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function notFound(string $resource, string $id): self {
        return new self(404, null, "$resource '$id' not found");
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        $body = ['schemas' => [self::SCHEMA], 'status' => (string)$this->status];
        if ($this->scimType !== null) {
            $body['scimType'] = $this->scimType->value;
        }
        $body['detail'] = $this->getMessage();
        return $body;
    }
}
