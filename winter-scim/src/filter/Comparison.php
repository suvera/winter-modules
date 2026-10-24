<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/** `attrPath op value`; the value is a string, int, float, bool or null as the client wrote it. */
final readonly class Comparison implements FilterNode {
    public function __construct(
        public AttributePath $path,
        public CompareOperator $operator,
        public string|int|float|bool|null $value,
    ) {
    }
}
