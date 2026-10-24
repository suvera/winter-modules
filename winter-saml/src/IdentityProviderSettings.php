<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

/** The IdP an app trusts: its entity ID, its sign-in URL and its signing certificates. */
final readonly class IdentityProviderSettings {
    /** @var list<string> base64 DER certificates, without PEM armour or whitespace */
    public array $certificates;

    /** @param list<string> $certificates PEM or base64 DER; more than one while the IdP rolls its certificate over */
    public function __construct(
        public string $entityId,
        public string $ssoUrl,
        array $certificates,
    ) {
        if ($certificates === []) {
            throw new SamlException('The identity provider needs at least one signing certificate');
        }
        $this->certificates = array_map(self::certificate(...), array_values($certificates));
    }

    private static function certificate(string $pem): string {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);
        $der = base64_decode((string)$body, true);
        if ($der === false || @openssl_x509_read(
            "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n"
        ) === false) {
            throw new SamlException('An identity provider certificate is not a valid X.509 certificate');
        }
        return base64_encode($der);
    }
}
