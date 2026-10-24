<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

/** The `scimType` of an error response (RFC 7644 §3.12, table 9). */
enum ScimErrorType: string {
    case INVALID_FILTER = 'invalidFilter';
    case TOO_MANY = 'tooMany';
    case UNIQUENESS = 'uniqueness';
    case MUTABILITY = 'mutability';
    case INVALID_SYNTAX = 'invalidSyntax';
    case INVALID_PATH = 'invalidPath';
    case NO_TARGET = 'noTarget';
    case INVALID_VALUE = 'invalidValue';
    case INVALID_VERS = 'invalidVers';
    case SENSITIVE = 'sensitive';
}
