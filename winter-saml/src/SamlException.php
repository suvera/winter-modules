<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

use RuntimeException;

/**
 * A SAML message the service provider refuses. The message says why, for the
 * log; show users a generic sign-in failure instead.
 */
final class SamlException extends RuntimeException {
}
