<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/** RFC 7643 §7 */
enum Mutability: string {
    case READ_ONLY = 'readOnly';
    case READ_WRITE = 'readWrite';
    case IMMUTABLE = 'immutable';
    case WRITE_ONLY = 'writeOnly';
}
