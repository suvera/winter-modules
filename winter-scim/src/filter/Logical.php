<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

final readonly class Logical implements FilterNode {
    public function __construct(
        public LogicalOperator $operator,
        public FilterNode $left,
        public FilterNode $right,
    ) {
    }
}
