<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/** `not (filter)` */
final readonly class Negation implements FilterNode {
    public function __construct(public FilterNode $filter) {
    }
}
