<?php

declare(strict_types=1);

namespace dev\winterframework\scim\server;

use dev\winterframework\scim\protocol\ListQuery;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\ResourceType;

/**
 * The storage side of one SCIM resource type, which each app implements
 * (the role of Scim2UserService / Scim2GroupService in scim2-sdk).
 * {@see ScimEndpoint} does the protocol: it validates input, applies PATCH
 * (get, apply, replace), and adds `schemas` and `meta.location` on the way out.
 *
 * Resources go in and out as SCIM arrays in canonical form (see
 * {@see \dev\winterframework\scim\protocol\ResourceValidator}). Returned resources
 * carry `id` (a string) and may carry `meta.created`, `meta.lastModified`
 * and `meta.version`; `schemas` is optional, the endpoint fills it in. Throw {@see ScimException} for a missing resource (404)
 * or a uniqueness conflict (409 `uniqueness`).
 */
interface ScimResourceService {
    public function resourceType(): ResourceType;

    /**
     * The page of $query: its filter (see DqlFilterConverter), `offset()` and
     * `count`. With `count` 0 return no resources, only the total.
     */
    public function list(ListQuery $query): ScimPage;

    /** @return array<string, mixed>|null */
    public function get(string $id): ?array;

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public function create(array $resource): array;

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public function replace(string $id, array $resource): array;

    public function delete(string $id): void;
}
