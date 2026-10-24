<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/**
 * A PATCH `path` (RFC 7644 §3.5.2): `attrPath`, or
 * `attrPath[valFilter]` with an optional `.subAttr` after the bracket, as in
 * `members[value eq "2819c223"]` or `emails[type eq "work"].value`.
 */
final readonly class PatchPath {
    public function __construct(
        /** The attribute; carries the sub-attribute when there is no filter (`name.givenName`). */
        public AttributePath $attribute,
        public ?FilterNode $filter = null,
        /** The sub-attribute after a filter (`.value` in `emails[…].value`). */
        public ?string $subAttribute = null,
    ) {
    }
}
