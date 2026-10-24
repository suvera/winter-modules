<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

enum LogicalOperator: string {
    case AND = 'and';
    case OR = 'or';
}
