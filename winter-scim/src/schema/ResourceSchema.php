<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/** A SCIM schema (RFC 7643 §7): the core User or Group schema, or an extension. */
final readonly class ResourceSchema {
    /** @param list<AttributeDefinition> $attributes */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $attributes,
    ) {
    }

    public function attribute(string $name): ?AttributeDefinition {
        foreach ($this->attributes as $attribute) {
            if (strcasecmp($attribute->name, $name) === 0) {
                return $attribute;
            }
        }
        return null;
    }

    /** @return array<string, mixed> the `/Schemas/{id}` representation */
    public function toArray(string $baseUrl): array {
        return [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:Schema'],
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'attributes' => array_map(static fn(AttributeDefinition $a) => $a->toArray(), $this->attributes),
            'meta' => [
                'resourceType' => 'Schema',
                'location' => rtrim($baseUrl, '/') . '/Schemas/' . $this->id,
            ],
        ];
    }
}
