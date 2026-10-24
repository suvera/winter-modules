<?php

declare(strict_types=1);

namespace dev\winterframework\saml;

use DateTimeImmutable;
use DateTimeZone;
use Dom\Element;
use DOMDocument;
use Nyholm\Psr7\ServerRequest;
use SimpleSAML\SAML2\Binding\HTTPPost;
use SimpleSAML\SAML2\Binding\HTTPRedirect;
use SimpleSAML\SAML2\Configuration\Destination;
use SimpleSAML\SAML2\Configuration\IdentityProvider;
use SimpleSAML\SAML2\Configuration\ServiceProvider;
use SimpleSAML\SAML2\Constants as C;
use SimpleSAML\SAML2\Response\Processor;
use SimpleSAML\SAML2\Type\SAMLAnyURIValue;
use SimpleSAML\SAML2\Type\SAMLDateTimeValue;
use SimpleSAML\SAML2\Type\SAMLStringValue;
use SimpleSAML\SAML2\XML\saml\Assertion;
use SimpleSAML\SAML2\XML\saml\AttributeValue;
use SimpleSAML\SAML2\XML\saml\Issuer;
use SimpleSAML\SAML2\XML\saml\NameID;
use SimpleSAML\SAML2\XML\samlp\AuthnRequest;
use SimpleSAML\SAML2\XML\samlp\NameIDPolicy;
use SimpleSAML\SAML2\XML\samlp\Response;
use SimpleSAML\XML\DOMDocumentFactory;
use SimpleSAML\XMLSchema\Type\BooleanValue;
use SimpleSAML\XMLSchema\Type\IDValue;
use SimpleSAML\XMLSchema\Type\Interface\ValueTypeInterface;
use Throwable;

/**
 * SP-side SAML 2.0 Web Browser SSO (SAML Profiles §4.1) over
 * simplesamlphp/saml2, with no superglobals and no PHP session, so it runs
 * under Swoole: the app keeps the pending request server-side between the
 * two steps and passes the POSTed form fields in.
 *
 *   $redirect = $sp->startLogin($spSettings, $idpSettings);   // store $redirect->requestId server-side
 *   $requestId = $sp->inResponseTo($post);                    // look the stored request up (one use)
 *   $identity = $sp->finishLogin($spSettings, $idpSettings, $post, $requestId, $replayStore);
 *
 * finishLogin accepts a response only when its signature (on the response or
 * the assertion) checks against the IdP's certificates, its issuer is the IdP,
 * it answers $requestId (or IdP-initiated sign-in is allowed and it answers
 * nothing), its Destination, Recipient and Audience are ours, it is within its
 * validity window (60 s skew), and its assertion was not used before.
 */
final class SamlServiceProvider {
    public function __construct() {
        SamlLibraryContainer::install();
    }

    /** An HTTP-Redirect binding AuthnRequest; $relayState comes back with the response (at most 80 bytes). */
    public function startLogin(ServiceProviderSettings $sp, IdentityProviderSettings $idp, ?string $relayState = null): AuthnRedirect {
        if ($relayState !== null && strlen($relayState) > 80) {
            throw new SamlException('RelayState is longer than 80 bytes');
        }
        $id = '_' . bin2hex(random_bytes(16));
        $request = new AuthnRequest(
            IDValue::fromString($id),
            SAMLDateTimeValue::fromDateTime(new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            nameIdPolicy: new NameIDPolicy(SAMLAnyURIValue::fromString($sp->nameIdFormat), AllowCreate: BooleanValue::fromBoolean(true)),
            assertionConsumerServiceURL: SAMLAnyURIValue::fromString($sp->acsUrl),
            protocolBinding: SAMLAnyURIValue::fromString(C::BINDING_HTTP_POST),
            issuer: new Issuer(SAMLStringValue::fromString($sp->entityId)),
            destination: SAMLAnyURIValue::fromString($idp->ssoUrl),
        );
        $binding = new HTTPRedirect();
        $binding->setRelayState($relayState);
        return new AuthnRedirect($binding->getRedirectURL($request), $id);
    }

    /**
     * Validates the IdP's HTTP-POST response.
     *
     * @param array<string, mixed> $post the form fields (SAMLResponse, RelayState)
     * @param string|null $requestId the ID startLogin returned for this browser, or null when there is none
     * @throws SamlException when the response is not acceptable
     */
    public function finishLogin(
        ServiceProviderSettings $sp,
        IdentityProviderSettings $idp,
        array $post,
        ?string $requestId,
        SamlReplayStore $replayStore,
    ): SamlIdentity {
        if (!is_string($post['SAMLResponse'] ?? null) || $post['SAMLResponse'] === '') {
            throw new SamlException('The POST carries no SAMLResponse');
        }
        $fields = ['SAMLResponse' => $post['SAMLResponse']];
        if (is_string($post['RelayState'] ?? null)) {
            $fields['RelayState'] = $post['RelayState'];
        }
        $binding = new HTTPPost();
        try {
            $response = $binding->receive(new ServerRequest('POST', $sp->acsUrl)->withParsedBody($fields));
        } catch (Throwable $e) {
            throw new SamlException('Unreadable SAML response: ' . $e->getMessage(), 0, $e);
        }
        if (!$response instanceof Response) {
            throw new SamlException('The message is not a SAML Response');
        }

        $issuer = $response->getIssuer()?->getContent()->getValue();
        if ($issuer !== null && $issuer !== $idp->entityId) {
            throw new SamlException("The response comes from '$issuer', not the configured identity provider");
        }
        $inResponseTo = $response->getInResponseTo()?->getValue();
        if ($requestId !== null ? $inResponseTo !== $requestId : ($inResponseTo !== null || !$sp->allowIdpInitiated)) {
            throw new SamlException($requestId === null && $inResponseTo === null
                ? 'IdP-initiated sign-in is not allowed'
                : 'The response does not answer this browser\'s sign-in request');
        }

        try {
            $assertions = new Processor(new SamlLibraryContainer()->getLogger())->process(
                new ServiceProvider(['entityId' => $sp->entityId]),
                new IdentityProvider(['entityId' => $idp->entityId, 'keys' => array_map(
                    static fn(string $cert) => ['type' => 'X509Certificate', 'signing' => true, 'encryption' => false, 'X509Certificate' => $cert],
                    $idp->certificates,
                )]),
                new Destination($sp->acsUrl),
                $response,
            );
        } catch (Throwable $e) {
            throw new SamlException('The SAML response was rejected: ' . $e->getMessage(), 0, $e);
        }
        $assertion = $assertions->getOnlyElement();
        if (!$assertion instanceof Assertion) {
            throw new SamlException('The response holds no usable assertion');
        }
        return $this->identity($assertion, $idp, $replayStore, $binding->getRelayState());
    }

    /**
     * The request ID a POSTed response says it answers, read without any
     * validation, so the app can look up what it stored for that request
     * before calling finishLogin. The IdP's POST is cross-site, so the
     * browser's SameSite=Lax session cookie does not come with it: keep the
     * pending request server-side (keyed by its ID), not in the session.
     *
     * @param array<string, mixed> $post
     */
    public function inResponseTo(array $post): ?string {
        $xml = is_string($post['SAMLResponse'] ?? null) ? base64_decode($post['SAMLResponse'], true) : false;
        if ($xml === false || $xml === '') {
            return null;
        }
        try {
            $value = DOMDocumentFactory::fromString($xml)->documentElement?->getAttribute('InResponseTo');
        } catch (Throwable) {
            return null;
        }
        return $value === null || $value === '' ? null : $value;
    }

    /** The SP's metadata XML, for the IdP administrator. */
    public function metadata(ServiceProviderSettings $sp): string {
        $md = 'urn:oasis:names:tc:SAML:2.0:metadata';
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $entity = $doc->appendChild($doc->createElementNS($md, 'md:EntityDescriptor'));
        $entity->setAttribute('entityID', $sp->entityId);
        $descriptor = $entity->appendChild($doc->createElementNS($md, 'md:SPSSODescriptor'));
        $descriptor->setAttribute('AuthnRequestsSigned', 'false');
        $descriptor->setAttribute('WantAssertionsSigned', 'true');
        $descriptor->setAttribute('protocolSupportEnumeration', C::NS_SAMLP);
        $descriptor->appendChild($doc->createElementNS($md, 'md:NameIDFormat'))->appendChild($doc->createTextNode($sp->nameIdFormat));
        $acs = $descriptor->appendChild($doc->createElementNS($md, 'md:AssertionConsumerService'));
        $acs->setAttribute('Binding', C::BINDING_HTTP_POST);
        $acs->setAttribute('Location', $sp->acsUrl);
        $acs->setAttribute('index', '0');
        $acs->setAttribute('isDefault', 'true');
        return (string)$doc->saveXML();
    }

    private function identity(Assertion $assertion, IdentityProviderSettings $idp, SamlReplayStore $replayStore, ?string $relayState): SamlIdentity {
        $issuer = $assertion->getIssuer()->getContent()->getValue();
        if ($issuer !== $idp->entityId) {
            throw new SamlException("The assertion comes from '$issuer', not the configured identity provider");
        }
        $nameId = $assertion->getSubject()?->getIdentifier();
        if (!$nameId instanceof NameID) {
            throw new SamlException('The assertion has no NameID');
        }

        $expires = $assertion->getConditions()?->getNotOnOrAfter()?->toDateTime();
        foreach ($assertion->getSubject()->getSubjectConfirmation() as $confirmation) {
            $until = $confirmation->getSubjectConfirmationData()?->getNotOnOrAfter()?->toDateTime();
            if ($until !== null && ($expires === null || $until > $expires)) {
                $expires = $until;
            }
        }
        $expiresAt = ($expires?->getTimestamp() ?? time() + 300) + 60;
        if (!$replayStore->claim($idp->entityId . '|' . $assertion->getId()->getValue(), $expiresAt)) {
            throw new SamlException('The assertion was already used');
        }

        $attributes = [];
        foreach ($assertion->getAttributeStatements() as $statement) {
            foreach ($statement->getAttributes() as $attribute) {
                $values = array_values(array_filter(array_map(self::text(...), $attribute->getAttributeValues()), static fn($v) => $v !== null));
                $attributes[$attribute->getName()->getValue()] = $values;
                $friendly = $attribute->getFriendlyName()?->getValue();
                if ($friendly !== null && !isset($attributes[$friendly])) {
                    $attributes[$friendly] = $values;
                }
            }
        }
        $sessionIndex = null;
        foreach ($assertion->getAuthnStatements() as $statement) {
            $sessionIndex ??= $statement->getSessionIndex()?->getValue();
        }
        return new SamlIdentity(
            $nameId->getContent()->getValue(),
            $nameId->getFormat()?->getValue(),
            $attributes,
            $sessionIndex,
            $relayState,
        );
    }

    private static function text(AttributeValue $value): ?string {
        $content = $value->getValue();
        if ($content instanceof ValueTypeInterface) {
            return $content->getValue();
        }
        if ($content instanceof Element || (is_object($content) && method_exists($content, 'toXML'))) {
            return null;
        }
        return $content === null ? null : (string)$content;
    }
}
