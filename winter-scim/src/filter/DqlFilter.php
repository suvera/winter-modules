<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

/** A DQL condition and its named parameters, from {@see DqlFilterConverter}. */
final readonly class DqlFilter {
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $dql,
        public array $parameters,
    ) {
    }
}
