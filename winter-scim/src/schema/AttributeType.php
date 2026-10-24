<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/** RFC 7643 §2.3 */
enum AttributeType: string {
    case STRING = 'string';
    case BOOLEAN = 'boolean';
    case DECIMAL = 'decimal';
    case INTEGER = 'integer';
    case DATE_TIME = 'dateTime';
    case BINARY = 'binary';
    case REFERENCE = 'reference';
    case COMPLEX = 'complex';
}
