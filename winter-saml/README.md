# Winter Boot Module - SAML 2.0 Service Provider

SP-initiated (and optionally IdP-initiated) SAML 2.0 Web Browser SSO for
Winter Boot apps, over [simplesamlphp/saml2](https://github.com/simplesamlphp/saml2)
v7. It reads no superglobals and uses no PHP session, so it runs under Swoole:
the app keeps the pending request server-side between the two steps.

## Setup

Needs `ext-bcmath`, `ext-dom`, `ext-openssl` and `ext-zlib`. 

In the app's own composer.json (Composer reads `allow-plugins` only from the root project), set
`"simplesamlphp/composer-xmlprovider-installer": false` under `allow-plugins`.

## Usage

```php
$sp = new ServiceProviderSettings('https://app.example.com/saml/metadata', 'https://app.example.com/saml/acs');
$idp = new IdentityProviderSettings($entityId, $ssoUrl, [$certificatePem]);
$saml = new SamlServiceProvider();

// GET /saml/login: keep the pending request server-side (Redis, ~10 minutes)
$redirect = $saml->startLogin($sp, $idp);
$redis->setEx('saml:req:' . $redirect->requestId, 600, json_encode(['return' => '/dashboard']));
return ResponseEntity::movedAway()->withHeader('Location', $redirect->url);

// POST /saml/acs: the IdP's cross-site POST carries no SameSite=Lax session cookie,
// so find the request by the response's InResponseTo, once.
$post = $request->getPostParams();
$requestId = $saml->inResponseTo($post);
$pending = $requestId === null ? null : $redis->getDel('saml:req:' . $requestId);
try {
    $identity = $saml->finishLogin($sp, $idp, $post, $pending === null ? null : $requestId, $replayStore);
} catch (SamlException $e) {
    // log $e->getMessage(); show a generic sign-in failure
}
// sign the user in (the new session cookie is set on this first-party response), then redirect
$identity->nameId; $identity->attribute('email');

// GET /saml/metadata
return ResponseEntity::ok($saml->metadata($sp))->withContentType('application/samlmetadata+xml');
```

`SamlReplayStore` remembers used assertion IDs until they expire; implement
it over Redis or a table when the app runs more than one process.

## What finishLogin checks

The signature (on the response or on the assertion) against the IdP's
certificates (several during a rollover); the issuer of the response and the
assertion; that the response answers this browser's request (or, with
`allowIdpInitiated`, answers none); Destination, Recipient and Audience;
NotBefore / NotOnOrAfter with 60 seconds of skew; one use per assertion.

Not supported yet: signed AuthnRequests, encrypted assertions, single logout.

## Tests

`winter-saml/srcTest/`: run `vendor/bin/phpunit --testsuite saml` from the
repo root. The test signs responses with keys it generates.
