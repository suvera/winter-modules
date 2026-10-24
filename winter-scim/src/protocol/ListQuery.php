<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use dev\winterframework\scim\filter\AttributePath;
use dev\winterframework\scim\filter\FilterNode;
use dev\winterframework\scim\filter\FilterParser;

/**
 * A list or search request (RFC 7644 §3.4.2, §3.4.3): the filter, 1-based
 * paging and the attributes to return. Sorting is not supported
 * (ServiceProviderConfig says so), so `sortBy` is ignored.
 */
final readonly class ListQuery {
    public const string SEARCH_SCHEMA = 'urn:ietf:params:scim:api:messages:2.0:SearchRequest';

    /**
     * @param list<AttributePath> $attributes
     * @param list<AttributePath> $excludedAttributes
     */
    public function __construct(
        public ?FilterNode $filter,
        public int $startIndex,
        public int $count,
        public array $attributes = [],
        public array $excludedAttributes = [],
    ) {
    }

    /** From the query string of `GET /Users`. */
    public static function fromQuery(array $query, int $maxResults): self {
        return self::from(
            Keys::get($query, 'filter'),
            Keys::get($query, 'startIndex'),
            Keys::get($query, 'count'),
            Keys::get($query, 'attributes'),
            Keys::get($query, 'excludedAttributes'),
            $maxResults,
        );
    }

    /** From the body of `POST /Users/.search`. */
    public static function fromSearchRequest(array $body, int $maxResults): self {
        $schemas = Keys::get($body, 'schemas');
        if (!is_array($schemas) || !in_array(self::SEARCH_SCHEMA, $schemas, true)) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, 'A search request must carry the ' . self::SEARCH_SCHEMA . ' schema');
        }
        return self::from(
            Keys::get($body, 'filter'),
            Keys::get($body, 'startIndex'),
            Keys::get($body, 'count'),
            Keys::get($body, 'attributes'),
            Keys::get($body, 'excludedAttributes'),
            $maxResults,
        );
    }

    /** Only `attributes` and `excludedAttributes`, for the responses of get, create, replace and patch. */
    public static function projection(array $query): self {
        return new self(null, 1, 0, self::paths(Keys::get($query, 'attributes')), self::paths(Keys::get($query, 'excludedAttributes')));
    }

    public function offset(): int {
        return $this->startIndex - 1;
    }

    private static function from(mixed $filter, mixed $startIndex, mixed $count, mixed $attributes, mixed $excluded, int $maxResults): self {
        if ($filter !== null && !is_string($filter)) {
            throw new ScimException(400, ScimErrorType::INVALID_FILTER, 'The filter must be a string');
        }
        $parsed = $filter === null || trim($filter) === '' ? null : FilterParser::parse($filter);
        $start = max(1, self::integer($startIndex, 'startIndex') ?? 1);
        $size = self::integer($count, 'count');
        $size = $size === null ? $maxResults : min(max(0, $size), $maxResults);
        return new self($parsed, $start, $size, self::paths($attributes), self::paths($excluded));
    }

    private static function integer(mixed $value, string $name): ?int {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            return (int)$value;
        }
        throw new ScimException(400, ScimErrorType::INVALID_VALUE, "'$name' must be an integer");
    }

    /** @return list<AttributePath> from a comma-separated string or a list */
    private static function paths(mixed $value): array {
        if ($value === null || $value === '') {
            return [];
        }
        $items = is_array($value) ? $value : explode(',', (string)$value);
        $paths = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $paths[] = AttributePath::parse($item, ScimErrorType::INVALID_VALUE);
            }
        }
        return $paths;
    }
}
