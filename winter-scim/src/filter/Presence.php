<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/** `attrPath pr` */
final readonly class Presence implements FilterNode {
    public function __construct(public AttributePath $path) {
    }
}
