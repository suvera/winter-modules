<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

/** This app as a SAML service provider. */
final readonly class ServiceProviderSettings {
    public const string NAMEID_EMAIL = 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress';
    public const string NAMEID_UNSPECIFIED = 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';

    public function __construct(
        public string $entityId,
        /** The assertion consumer service URL (HTTP-POST binding). */
        public string $acsUrl,
        public string $nameIdFormat = self::NAMEID_EMAIL,
        /** Accept responses that answer no request of ours (IdP-initiated sign-in). Off: such responses are refused. */
        public bool $allowIdpInitiated = false,
    ) {
    }
}
