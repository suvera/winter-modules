<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

/** RFC 7644 §3.4.2 */
final class ListResponse {
    public const string SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:ListResponse';

    /**
     * @param list<array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    public static function toArray(array $resources, int $totalResults, int $startIndex): array {
        return [
            'schemas' => [self::SCHEMA],
            'totalResults' => $totalResults,
            'startIndex' => $startIndex,
            'itemsPerPage' => count($resources),
            'Resources' => $resources,
        ];
    }
}
