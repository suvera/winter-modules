<?php

declare(strict_types=1);

namespace dev\winterframework\scim\server;

/** One page of a list: the resources and the total that matched the filter. */
final readonly class ScimPage {
    /** @param list<array<string, mixed>> $resources */
    public function __construct(
        public array $resources,
        public int $totalResults,
    ) {
    }
}
