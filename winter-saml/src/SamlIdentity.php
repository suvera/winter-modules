<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

/** Who the IdP says signed in, from a validated assertion. */
final readonly class SamlIdentity {
    /** @param array<string, list<string>> $attributes by attribute Name and, when the IdP sends one, FriendlyName */
    public function __construct(
        public string $nameId,
        public ?string $nameIdFormat,
        public array $attributes,
        public ?string $sessionIndex,
        public ?string $relayState,
    ) {
    }

    /** The first value of an attribute, matching its name case-insensitively. */
    public function attribute(string $name): ?string {
        foreach ($this->attributes as $key => $values) {
            if (strcasecmp($key, $name) === 0 && $values !== []) {
                return $values[0];
            }
        }
        return null;
    }
}
