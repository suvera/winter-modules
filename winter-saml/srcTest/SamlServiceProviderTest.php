<?php

declare(strict_types=1);

namespace test\winterframework\saml;

use DateTimeImmutable;
use DateTimeZone;
use dev\winterframework\saml\IdentityProviderSettings;
use dev\winterframework\saml\SamlException;
use dev\winterframework\saml\SamlReplayStore;
use dev\winterframework\saml\SamlServiceProvider;
use dev\winterframework\saml\ServiceProviderSettings;
use PHPUnit\Framework\TestCase;
use SimpleSAML\SAML2\Constants as C;
use SimpleSAML\SAML2\Type\SAMLAnyURIValue;
use SimpleSAML\SAML2\Type\SAMLDateTimeValue;
use SimpleSAML\SAML2\Type\SAMLStringValue;
use SimpleSAML\SAML2\XML\saml\Assertion;
use SimpleSAML\SAML2\XML\saml\Issuer;
use SimpleSAML\SAML2\XML\samlp\Response;
use SimpleSAML\SAML2\XML\samlp\Status;
use SimpleSAML\SAML2\XML\samlp\StatusCode;
use SimpleSAML\XML\DOMDocumentFactory;
use SimpleSAML\XMLSchema\Type\IDValue;
use SimpleSAML\XMLSchema\Type\NCNameValue;
use SimpleSAML\XMLSecurity\Alg\Signature\SignatureAlgorithmFactory;
use SimpleSAML\XMLSecurity\Key\PrivateKey;

class SamlServiceProviderTest extends TestCase {
    private const string IDP = 'https://idp.example.test/metadata';
    private const string SSO = 'https://idp.example.test/sso';
    private const string SP = 'https://app.example.test/saml/metadata';
    private const string ACS = 'https://app.example.test/saml/acs';

    /** @var array{0: string, 1: string} private key PEM, certificate PEM */
    private static array $idpKey;
    private static array $otherKey;

    private SamlServiceProvider $provider;
    private ServiceProviderSettings $sp;
    private IdentityProviderSettings $idp;
    private MemoryReplayStore $replay;

    public static function setUpBeforeClass(): void {
        self::$idpKey = self::keyPair();
        self::$otherKey = self::keyPair();
    }

    protected function setUp(): void {
        $this->provider = new SamlServiceProvider();
        $this->sp = new ServiceProviderSettings(self::SP, self::ACS);
        $this->idp = new IdentityProviderSettings(self::IDP, self::SSO, [self::$idpKey[1]]);
        $this->replay = new MemoryReplayStore();
    }

    public function test_start_login_redirects_with_a_deflated_authn_request(): void {
        $redirect = $this->provider->startLogin($this->sp, $this->idp, 'back-to/here');

        $this->assertStringStartsWith(self::SSO . '?SAMLRequest=', $redirect->url);
        parse_str((string)parse_url($redirect->url, PHP_URL_QUERY), $query);
        $this->assertSame('back-to/here', $query['RelayState']);
        $xml = gzinflate(base64_decode($query['SAMLRequest']));
        $this->assertStringContainsString('ID="' . $redirect->requestId . '"', $xml);
        $this->assertStringContainsString('AssertionConsumerServiceURL="' . self::ACS . '"', $xml);
        $this->assertStringContainsString('>' . self::SP . '</saml:Issuer>', $xml);
    }

    public function test_a_signed_answer_to_our_request_signs_in(): void {
        $post = ['SAMLResponse' => $this->response('_req1'), 'RelayState' => 'r'];

        $identity = $this->provider->finishLogin($this->sp, $this->idp, $post, '_req1', $this->replay);

        $this->assertSame('bjensen@example.com', $identity->nameId);
        $this->assertSame(ServiceProviderSettings::NAMEID_EMAIL, $identity->nameIdFormat);
        $this->assertSame('Barbara', $identity->attribute('givenname'));
        $this->assertSame(['staff', 'admin'], $identity->attributes['http://schemas.xmlsoap.org/claims/Group']);
        $this->assertSame('_session1', $identity->sessionIndex);
        $this->assertSame('r', $identity->relayState);
    }

    public function test_a_signed_response_around_an_unsigned_assertion_signs_in(): void {
        $post = ['SAMLResponse' => $this->response('_req1', signAssertion: false, signResponse: true)];

        $this->assertSame('bjensen@example.com', $this->provider->finishLogin($this->sp, $this->idp, $post, '_req1', $this->replay)->nameId);
    }

    public function test_certificate_rollover_accepts_either_certificate(): void {
        $idp = new IdentityProviderSettings(self::IDP, self::SSO, [self::$otherKey[1], self::$idpKey[1]]);

        $this->assertSame('bjensen@example.com', $this->provider->finishLogin($this->sp, $idp, ['SAMLResponse' => $this->response('_r')], '_r', $this->replay)->nameId);
    }

    /** @return array<string, array{0: callable(self): array, 1: string}> */
    public static function rejected(): array {
        return [
            'unsigned' => [fn(self $t) => [$t->response('_r', signAssertion: false), '_r'], 'not signed'],
            'signed by another key' => [fn(self $t) => [$t->response('_r', key: self::$otherKey), '_r'], 'rejected'],
            'tampered after signing' => [fn(self $t) => [base64_encode(str_replace('bjensen@', 'mallory@', base64_decode($t->response('_r')))), '_r'], 'rejected'],
            'another issuer' => [fn(self $t) => [$t->response('_r', issuer: 'https://evil.example.test'), '_r'], 'not the configured identity provider'],
            'another request' => [fn(self $t) => [$t->response('_other'), '_r'], 'does not answer'],
            'unsolicited' => [fn(self $t) => [$t->response(null), null], 'IdP-initiated sign-in is not allowed'],
            'another audience' => [fn(self $t) => [$t->response('_r', audience: 'https://other.example.test'), '_r'], 'rejected'],
            'another recipient' => [fn(self $t) => [$t->response('_r', destination: 'https://other.example.test/acs'), '_r'], 'rejected'],
            'expired' => [fn(self $t) => [$t->response('_r', validUntil: '-10 minutes'), '_r'], 'rejected'],
            'not a response' => [fn(self $t) => [base64_encode('<x/>'), '_r'], 'Unreadable'],
            'not base64 XML' => [fn(self $t) => ['%%%', '_r'], 'Unreadable'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function test_rejected_responses(callable $make, string $reason): void {
        [$response, $requestId] = $make($this);
        try {
            $this->provider->finishLogin($this->sp, $this->idp, ['SAMLResponse' => $response], $requestId, $this->replay);
            $this->fail('accepted');
        } catch (SamlException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    public function test_in_response_to_reads_the_request_id_before_validation(): void {
        $this->assertSame('_req9', $this->provider->inResponseTo(['SAMLResponse' => $this->response('_req9')]));
        $this->assertNull($this->provider->inResponseTo(['SAMLResponse' => $this->response(null)]));
        $this->assertNull($this->provider->inResponseTo(['SAMLResponse' => '%%%']));
        $this->assertNull($this->provider->inResponseTo([]));
    }

    public function test_a_response_can_be_used_once(): void {
        $post = ['SAMLResponse' => $this->response('_r')];
        $this->provider->finishLogin($this->sp, $this->idp, $post, '_r', $this->replay);

        $this->expectException(SamlException::class);
        $this->expectExceptionMessage('already used');
        $this->provider->finishLogin($this->sp, $this->idp, $post, '_r', $this->replay);
    }

    public function test_idp_initiated_sign_in_when_allowed(): void {
        $sp = new ServiceProviderSettings(self::SP, self::ACS, allowIdpInitiated: true);

        $this->assertSame('bjensen@example.com', $this->provider->finishLogin($sp, $this->idp, ['SAMLResponse' => $this->response(null)], null, $this->replay)->nameId);
    }

    public function test_metadata(): void {
        $xml = $this->provider->metadata($this->sp);

        $this->assertStringContainsString('entityID="' . self::SP . '"', $xml);
        $this->assertStringContainsString('Location="' . self::ACS . '"', $xml);
        $this->assertStringContainsString('WantAssertionsSigned="true"', $xml);
    }

    public function test_settings_reject_a_bad_certificate(): void {
        $this->expectException(SamlException::class);
        new IdentityProviderSettings(self::IDP, self::SSO, ['not a certificate']);
    }

    /** A response as an IdP would post it, base64-encoded. */
    private function response(
        ?string $inResponseTo,
        bool $signAssertion = true,
        bool $signResponse = false,
        ?array $key = null,
        string $issuer = self::IDP,
        string $audience = self::SP,
        string $destination = self::ACS,
        string $validUntil = '+5 minutes',
    ): string {
        $key ??= self::$idpKey;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $time = static fn(string $offset) => $now->modify($offset)->format('Y-m-d\TH:i:s\Z');
        $id = '_a' . bin2hex(random_bytes(8));
        $answer = $inResponseTo === null ? '' : ' InResponseTo="' . $inResponseTo . '"';
        $xml = <<<XML
            <saml:Assertion xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ID="$id" Version="2.0" IssueInstant="{$time('now')}">
              <saml:Issuer>$issuer</saml:Issuer>
              <saml:Subject>
                <saml:NameID Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress">bjensen@example.com</saml:NameID>
                <saml:SubjectConfirmation Method="urn:oasis:names:tc:SAML:2.0:cm:bearer">
                  <saml:SubjectConfirmationData NotOnOrAfter="{$time($validUntil)}" Recipient="$destination"$answer/>
                </saml:SubjectConfirmation>
              </saml:Subject>
              <saml:Conditions NotBefore="{$time('-30 minutes')}" NotOnOrAfter="{$time($validUntil)}">
                <saml:AudienceRestriction><saml:Audience>$audience</saml:Audience></saml:AudienceRestriction>
              </saml:Conditions>
              <saml:AuthnStatement AuthnInstant="{$time('now')}" SessionIndex="_session1">
                <saml:AuthnContext><saml:AuthnContextClassRef>urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport</saml:AuthnContextClassRef></saml:AuthnContext>
              </saml:AuthnStatement>
              <saml:AttributeStatement>
                <saml:Attribute Name="http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname" FriendlyName="givenName"><saml:AttributeValue xsi:type="xs:string">Barbara</saml:AttributeValue></saml:Attribute>
                <saml:Attribute Name="http://schemas.xmlsoap.org/claims/Group"><saml:AttributeValue xsi:type="xs:string">staff</saml:AttributeValue><saml:AttributeValue xsi:type="xs:string">admin</saml:AttributeValue></saml:Attribute>
              </saml:AttributeStatement>
            </saml:Assertion>
            XML;
        // A key from another IdP never matches, so the "givenname" claim checks friendly-name lookup too.
        $assertion = Assertion::fromXML(DOMDocumentFactory::fromString(preg_replace('/>\s+</', '><', $xml))->documentElement);
        $signer = new SignatureAlgorithmFactory()->getAlgorithm(C::SIG_RSA_SHA256, PrivateKey::fromFile($key[0]));
        if ($signAssertion) {
            $assertion->sign($signer);
            $assertion = Assertion::fromXML($assertion->toXML());
        }
        $response = new Response(
            id: IDValue::fromString('_r' . bin2hex(random_bytes(8))),
            status: new Status(new StatusCode(SAMLAnyURIValue::fromString(C::STATUS_SUCCESS))),
            issueInstant: SAMLDateTimeValue::fromDateTime($now),
            issuer: new Issuer(SAMLStringValue::fromString($issuer)),
            inResponseTo: $inResponseTo === null ? null : NCNameValue::fromString($inResponseTo),
            destination: SAMLAnyURIValue::fromString($destination),
            assertions: [$assertion],
        );
        if ($signResponse) {
            $response->sign($signer);
        }
        $element = $response->toXML();
        return base64_encode($element->ownerDocument->saveXML($element));
    }

    /** @return array{0: string, 1: string} */
    private static function keyPair(): array {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'idp.example.test'], $key), null, $key, 30);
        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($cert, $certPem);
        return [$keyPem, $certPem];
    }
}

final class MemoryReplayStore implements SamlReplayStore {
    /** @var array<string, int> */
    private array $claimed = [];

    public function claim(string $assertionId, int $expiresAt): bool {
        if (isset($this->claimed[$assertionId])) {
            return false;
        }
        $this->claimed[$assertionId] = $expiresAt;
        return true;
    }
}
