<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

use dev\winterframework\scim\filter\AttributePath;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;

/** A resource type (RFC 7643 §6): its endpoint, core schema and schema extensions. */
final readonly class ResourceType {
    /**
     * @param list<ResourceSchema> $extensions
     * @param list<string> $requiredExtensions URNs of the extensions every resource must carry
     */
    public function __construct(
        public string $id,
        public string $endpoint,
        public string $description,
        public ResourceSchema $schema,
        public array $extensions = [],
        public array $requiredExtensions = [],
    ) {
    }

    public static function user(bool $enterprise = true): self {
        return $enterprise
            ? new self('User', '/Users', 'User Account', CoreSchemas::user(), [CoreSchemas::enterpriseUser()])
            : new self('User', '/Users', 'User Account', CoreSchemas::user());
    }

    public static function group(): self {
        return new self('Group', '/Groups', 'Group', CoreSchemas::group());
    }

    /** The core schema or one of the extensions, by URN (case-insensitive). */
    public function schemaById(string $urn): ?ResourceSchema {
        if (strcasecmp($urn, $this->schema->id) === 0) {
            return $this->schema;
        }
        foreach ($this->extensions as $extension) {
            if (strcasecmp($urn, $extension->id) === 0) {
                return $extension;
            }
        }
        return null;
    }

    /**
     * The definitions of $path, or null when the schema has no such attribute.
     * A schema URN this type does not have is the client's error.
     */
    public function resolve(AttributePath $path, ScimErrorType $error = ScimErrorType::INVALID_FILTER): ?ResolvedAttribute {
        $schema = $path->schema === null ? $this->schema : $this->schemaById($path->schema);
        if ($schema === null) {
            throw new ScimException(400, $error, "Unknown schema '{$path->schema}' for $this->id");
        }
        $inExtension = $schema !== $this->schema;
        $attribute = $schema->attribute($path->name);
        if ($attribute === null && !$inExtension) {
            foreach (CoreSchemas::commonAttributes() as $common) {
                if (strcasecmp($common->name, $path->name) === 0) {
                    $attribute = $common;
                }
            }
        }
        if ($attribute === null) {
            return null;
        }
        $sub = null;
        if ($path->subAttribute !== null) {
            $sub = $attribute->subAttribute($path->subAttribute);
            if ($sub === null) {
                return null;
            }
        }
        return new ResolvedAttribute($schema, $attribute, $sub, $inExtension);
    }

    /** @return array<string, mixed> the `/ResourceTypes/{id}` representation */
    public function toArray(string $baseUrl): array {
        $out = [
            'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:ResourceType'],
            'id' => $this->id,
            'name' => $this->id,
            'endpoint' => $this->endpoint,
            'description' => $this->description,
            'schema' => $this->schema->id,
        ];
        if ($this->extensions !== []) {
            $out['schemaExtensions'] = [];
            foreach ($this->extensions as $extension) {
                $out['schemaExtensions'][] = ['schema' => $extension->id, 'required' => in_array($extension->id, $this->requiredExtensions, true)];
            }
        }
        $out['meta'] = [
            'resourceType' => 'ResourceType',
            'location' => rtrim($baseUrl, '/') . '/ResourceTypes/' . $this->id,
        ];
        return $out;
    }
}
