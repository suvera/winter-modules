<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

use DateTimeImmutable;
use DateTimeZone;

/** Who the IdP says signed in, from a validated assertion. */
final readonly class SamlIdentity {
    /**
     * @param array<string, list<string>> $attributes by attribute Name and, when the IdP sends one, FriendlyName
     * @param DateTimeImmutable|null $authnInstant when the person last actually signed in at the IdP (the latest
     *        AuthnStatement's AuthnInstant), which is older than the response when the IdP reused its session
     */
    public function __construct(
        public string $nameId,
        public ?string $nameIdFormat,
        public array $attributes,
        public ?string $sessionIndex,
        public ?string $relayState,
        public ?DateTimeImmutable $authnInstant = null,
    ) {
    }

    /**
     * Whether the person signed in at the IdP at most $seconds ago (60 seconds of clock skew allowed ahead).
     * After a ForceAuthn request this tells a fresh sign-in from an IdP that ignored ForceAuthn.
     */
    public function authenticatedWithin(int $seconds, ?DateTimeImmutable $now = null): bool {
        if ($this->authnInstant === null) {
            return false;
        }
        $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp();
        $at = $this->authnInstant->getTimestamp();
        return $at >= $now - $seconds && $at <= $now + 60;
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
