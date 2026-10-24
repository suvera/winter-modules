<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/**
 * What `ScimEndpoint` supports (RFC 7643 §5): PATCH and filtering; no bulk,
 * sorting, ETags or password change. Authentication is an OAuth bearer token.
 */
final readonly class ServiceProviderConfig {
    public function __construct(
        public int $maxResults,
        public string $documentationUri = '',
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(string $baseUrl): array {
        $out = ['schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ServiceProviderConfig']];
        if ($this->documentationUri !== '') {
            $out['documentationUri'] = $this->documentationUri;
        }
        return $out + [
            'patch' => ['supported' => true],
            'bulk' => ['supported' => false, 'maxOperations' => 0, 'maxPayloadSize' => 0],
            'filter' => ['supported' => true, 'maxResults' => $this->maxResults],
            'changePassword' => ['supported' => false],
            'sort' => ['supported' => false],
            'etag' => ['supported' => false],
            'authenticationSchemes' => [[
                'type' => 'oauthbearertoken',
                'name' => 'OAuth Bearer Token',
                'description' => 'A bearer token sent in the Authorization header.',
                'primary' => true,
            ]],
            'meta' => [
                'resourceType' => 'ServiceProviderConfig',
                'location' => rtrim($baseUrl, '/') . '/ServiceProviderConfig',
            ],
        ];
    }
}
