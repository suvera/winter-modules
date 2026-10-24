<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

/**
 * Remembers the assertion IDs already used, so a captured response cannot be
 * posted twice. Implement it over a shared store (Redis, a database table) when
 * the app runs more than one process.
 */
interface SamlReplayStore {
    /** True the first time $assertionId is claimed; false when it was claimed before and has not expired. */
    public function claim(string $assertionId, int $expiresAt): bool;
}
