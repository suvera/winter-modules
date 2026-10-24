<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use dev\winterframework\scim\filter\AttributePath;
use dev\winterframework\scim\schema\AttributeDefinition;
use dev\winterframework\scim\schema\CoreSchemas;
use dev\winterframework\scim\schema\ResourceSchema;
use dev\winterframework\scim\schema\Returned;
use dev\winterframework\scim\schema\ResourceType;

/**
 * Applies `attributes` / `excludedAttributes` and the schema's `returned`
 * rules to a resource on its way out (RFC 7644 §3.9, RFC 7643 §7): `never`
 * attributes (password) are always dropped, `always` ones (id) always kept,
 * `request` ones only when asked for.
 */
final readonly class AttributeSelector {
    public function __construct(private ResourceType $type) {
    }

    /**
     * @param array<string, mixed> $resource
     * @param list<AttributePath> $attributes
     * @param list<AttributePath> $excluded
     * @return array<string, mixed>
     */
    public function select(array $resource, array $attributes = [], array $excluded = []): array {
        $out = [];
        foreach ($resource as $key => $value) {
            if ($key === 'schemas') {
                $out[$key] = $value;
                continue;
            }
            $extension = $this->type->schemaById((string)$key);
            if ($extension !== null && $extension !== $this->type->schema && is_array($value)) {
                $selected = $this->object($value, $extension, false, $attributes, $excluded);
                if ($selected !== []) {
                    $out[$key] = $selected;
                }
                continue;
            }
            $selected = $this->object([$key => $value], $this->type->schema, true, $attributes, $excluded);
            $out += $selected;
        }
        return $out;
    }

    /**
     * @param list<AttributePath> $attributes
     * @param list<AttributePath> $excluded
     */
    private function object(array $object, ResourceSchema $schema, bool $core, array $attributes, array $excluded): array {
        $out = [];
        foreach ($object as $key => $value) {
            $definition = $this->definition($schema, $core, (string)$key);
            if ($definition === null) {
                $out[$key] = $value;
                continue;
            }
            if ($definition->returned === Returned::NEVER) {
                continue;
            }
            if ($definition->returned === Returned::ALWAYS) {
                $out[$key] = $value;
                continue;
            }
            $named = $this->named($attributes, $schema, $core, $definition->name);
            if ($attributes !== []) {
                if ($named === true) {
                    $out[$key] = $value;
                } elseif ($named !== [] && is_array($value)) {
                    $out[$key] = $this->subs($value, $definition, $named, true);
                }
                continue;
            }
            if ($definition->returned === Returned::REQUEST) {
                continue;
            }
            $dropped = $this->named($excluded, $schema, $core, $definition->name);
            if ($dropped === true) {
                continue;
            }
            $out[$key] = $dropped !== [] && is_array($value) ? $this->subs($value, $definition, $dropped, false) : $value;
        }
        return $out;
    }

    /**
     * True when $paths name the whole attribute, else the sub-attribute names they name.
     *
     * @param list<AttributePath> $paths
     * @return true|list<string>
     */
    private function named(array $paths, ResourceSchema $schema, bool $core, string $name): true|array {
        $subs = [];
        foreach ($paths as $path) {
            $inSchema = $path->schema === null ? $core : strcasecmp($path->schema, $schema->id) === 0;
            if (!$inSchema || strcasecmp($path->name, $name) !== 0) {
                continue;
            }
            if ($path->subAttribute === null) {
                return true;
            }
            $subs[] = $path->subAttribute;
        }
        return $subs;
    }

    /** @param list<string> $names */
    private function subs(array $value, AttributeDefinition $definition, array $names, bool $keep): array {
        $pick = function (array $object) use ($definition, $names, $keep): array {
            $out = [];
            foreach ($object as $key => $item) {
                $sub = $definition->subAttribute((string)$key);
                $listed = in_array(strtolower((string)$key), array_map('strtolower', $names), true);
                if ($sub?->returned === Returned::NEVER) {
                    continue;
                }
                if ($sub?->returned === Returned::ALWAYS || $listed === $keep) {
                    $out[$key] = $item;
                }
            }
            return $out;
        };
        if (array_is_list($value)) {
            return array_values(array_filter(array_map(static fn($item) => is_array($item) ? $pick($item) : $item, $value), static fn($item) => $item !== []));
        }
        return $pick($value);
    }

    private function definition(ResourceSchema $schema, bool $core, string $name): ?AttributeDefinition {
        $definition = $schema->attribute($name);
        if ($definition !== null || !$core) {
            return $definition;
        }
        foreach (CoreSchemas::commonAttributes() as $common) {
            if (strcasecmp($common->name, $name) === 0) {
                return $common;
            }
        }
        return null;
    }
}
