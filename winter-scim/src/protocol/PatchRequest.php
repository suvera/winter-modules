<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use dev\winterframework\scim\filter\FilterParser;

/**
 * A PATCH body (RFC 7644 §3.5.2). Operation names are case-insensitive
 * because Entra ID sends `Add`, `Replace` and `Remove`.
 */
final readonly class PatchRequest {
    public const string SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:PatchOp';

    /** @param list<PatchOperation> $operations */
    public function __construct(public array $operations) {
    }

    public static function fromArray(array $body): self {
        $schemas = Keys::get($body, 'schemas');
        if (!is_array($schemas) || !in_array(self::SCHEMA, $schemas, true)) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, 'A PATCH request must carry the ' . self::SCHEMA . ' schema');
        }
        $operations = Keys::get($body, 'Operations');
        if (!is_array($operations) || !array_is_list($operations) || $operations === []) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, "'Operations' must be a non-empty list");
        }
        $parsed = [];
        foreach ($operations as $i => $operation) {
            if (!is_array($operation) || array_is_list($operation)) {
                throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, "Operation $i must be an object");
            }
            $name = Keys::get($operation, 'op');
            $op = is_string($name) ? PatchOp::tryFrom(strtolower($name)) : null;
            if ($op === null) {
                throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, "Operation $i: 'op' must be add, remove or replace");
            }
            $path = Keys::get($operation, 'path');
            if ($path !== null && (!is_string($path) || trim($path) === '')) {
                throw new ScimException(400, ScimErrorType::INVALID_PATH, "Operation $i: 'path' must be a string");
            }
            if ($path === null && $op === PatchOp::REMOVE) {
                throw new ScimException(400, ScimErrorType::NO_TARGET, "Operation $i: remove needs a path");
            }
            $hasValue = Keys::find($operation, 'value') !== null;
            if (!$hasValue && $op !== PatchOp::REMOVE) {
                throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, "Operation $i: {$op->value} needs a value");
            }
            $value = Keys::get($operation, 'value');
            if ($path === null && (!is_array($value) || (array_is_list($value) && $value !== []))) {
                throw new ScimException(400, ScimErrorType::INVALID_VALUE, "Operation $i: without a path, the value must be an object of attributes");
            }
            $parsed[] = new PatchOperation($op, $path === null ? null : FilterParser::parsePath(trim($path)), $value);
        }
        return new self($parsed);
    }
}
