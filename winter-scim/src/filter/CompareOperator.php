<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

enum CompareOperator: string {
    case EQ = 'eq';
    case NE = 'ne';
    case CO = 'co';
    case SW = 'sw';
    case EW = 'ew';
    case GT = 'gt';
    case LT = 'lt';
    case GE = 'ge';
    case LE = 'le';
}
