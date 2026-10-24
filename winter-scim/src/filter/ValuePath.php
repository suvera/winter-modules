<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/**
 * `emails[type eq "work" and value co "@x"]`: one value of the multi-valued
 * attribute must match the whole inner filter, whose paths are relative to it.
 */
final readonly class ValuePath implements FilterNode {
    public function __construct(
        public AttributePath $path,
        public FilterNode $filter,
    ) {
    }
}
