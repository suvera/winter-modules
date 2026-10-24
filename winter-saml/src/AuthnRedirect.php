<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

/** Where to send the browser to sign in, and the request ID the response must answer. */
final readonly class AuthnRedirect {
    public function __construct(
        public string $url,
        public string $requestId,
    ) {
    }
}
