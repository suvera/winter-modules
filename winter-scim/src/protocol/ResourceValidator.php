<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use dev\winterframework\scim\filter\ComparisonValue;
use dev\winterframework\scim\schema\AttributeDefinition;
use dev\winterframework\scim\schema\AttributeType;
use dev\winterframework\scim\schema\CoreSchemas;
use dev\winterframework\scim\schema\Mutability;
use dev\winterframework\scim\schema\ResourceSchema;
use dev\winterframework\scim\schema\ResourceType;

/**
 * Checks a resource a client sent (POST, PUT, or the result of a PATCH)
 * against its schemas and returns it in canonical form: attribute names in
 * the schema's spelling, extensions under their URN, `schemas` listing the
 * core schema and the extensions present. What it drops: read-only
 * attributes (`id`, `meta`, `groups`, `members.display`…, which clients echo
 * back), unknown attributes, and nulls and empty lists (unassigned, RFC 7643
 * §2.5). What it rejects (400): a missing core schema, a wrong type, a
 * missing required attribute, and a change to an immutable attribute.
 *
 * Booleans also accept the strings "true" and "false" in any case, which
 * Entra ID sends for `active`.
 */
final readonly class ResourceValidator {
    public function __construct(private ResourceType $type) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing the stored resource, for replace and patch
     * @return array<string, mixed>
     */
    public function normalize(array $input, ?array $existing = null): array {
        if ($input !== [] && array_is_list($input)) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, 'The resource must be a JSON object');
        }
        $schemas = Keys::get($input, 'schemas');
        if (!is_array($schemas) || !in_array(strtolower($this->type->schema->id), array_map(static fn($s) => strtolower((string)$s), $schemas), true)) {
            throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, "'schemas' must include " . $this->type->schema->id);
        }

        $out = ['schemas' => [$this->type->schema->id]];
        $out += $this->object($input, $this->type->schema, true, $existing ?? []);
        foreach ($this->type->extensions as $extension) {
            $value = Keys::get($input, $extension->id);
            if ($value === null) {
                continue;
            }
            if (!is_array($value) || (array_is_list($value) && $value !== [])) {
                throw new ScimException(400, ScimErrorType::INVALID_SYNTAX, "'{$extension->id}' must be an object");
            }
            $previous = Keys::get($existing ?? [], $extension->id);
            $normalized = $this->object($value, $extension, false, is_array($previous) ? $previous : []);
            if ($normalized !== []) {
                $out['schemas'][] = $extension->id;
                $out[$extension->id] = $normalized;
            }
        }
        foreach ($this->type->requiredExtensions as $urn) {
            if (!isset($out[$urn])) {
                throw new ScimException(400, ScimErrorType::INVALID_VALUE, "The '$urn' extension is required");
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private function object(array $input, ResourceSchema $schema, bool $core, array $existing): array {
        $definitions = $core ? [...CoreSchemas::commonAttributes(), ...$schema->attributes] : $schema->attributes;
        $out = [];
        foreach ($definitions as $definition) {
            $value = Keys::get($input, $definition->name);
            $previous = Keys::get($existing, $definition->name);
            if ($definition->mutability === Mutability::READ_ONLY) {
                continue;
            }
            $value = $this->value($definition, $value, $definition->name);
            if ($definition->mutability === Mutability::IMMUTABLE && $previous !== null && $value !== $previous) {
                throw new ScimException(400, ScimErrorType::MUTABILITY, "'{$definition->name}' is immutable");
            }
            if ($value === null) {
                if ($definition->required) {
                    throw new ScimException(400, ScimErrorType::INVALID_VALUE, "'{$definition->name}' is required");
                }
                continue;
            }
            $out[$definition->name] = $value;
        }
        return $out;
    }

    private function value(AttributeDefinition $definition, mixed $value, string $path): mixed {
        if ($value === null) {
            return null;
        }
        if ($definition->multiValued) {
            if (is_array($value) && !array_is_list($value)) {
                $value = [$value];
            } elseif (!is_array($value)) {
                $value = [$value];
            }
            $values = [];
            foreach ($value as $item) {
                $item = $this->single($definition, $item, $path);
                if ($item !== null && !in_array($item, $values, true)) {
                    $values[] = $item;
                }
            }
            return $values === [] ? null : $values;
        }
        return $this->single($definition, $value, $path);
    }

    private function single(AttributeDefinition $definition, mixed $value, string $path): mixed {
        if ($value === null) {
            return null;
        }
        switch ($definition->type) {
            case AttributeType::COMPLEX:
                if (!is_array($value) || (array_is_list($value) && $value !== [])) {
                    throw self::invalid("'$path' must be an object");
                }
                $out = [];
                foreach ($definition->subAttributes as $sub) {
                    if ($sub->mutability === Mutability::READ_ONLY) {
                        continue;
                    }
                    $subValue = $this->value($sub, Keys::get($value, $sub->name), "$path.{$sub->name}");
                    if ($subValue !== null) {
                        $out[$sub->name] = $subValue;
                    }
                }
                return $out === [] ? null : $out;
            case AttributeType::BOOLEAN:
                if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                    return strtolower($value) === 'true';
                }
                return is_bool($value) ? $value : throw self::invalid("'$path' must be a boolean");
            case AttributeType::INTEGER:
                return is_int($value) ? $value : throw self::invalid("'$path' must be an integer");
            case AttributeType::DECIMAL:
                return is_int($value) || is_float($value) ? $value : throw self::invalid("'$path' must be a number");
            case AttributeType::DATE_TIME:
                return is_string($value) && ComparisonValue::instant($value) !== null ? $value : throw self::invalid("'$path' must be an xsd:dateTime");
            default:
                if (is_int($value) || is_float($value)) {
                    $value = (string)$value;
                }
                if (!is_string($value)) {
                    throw self::invalid("'$path' must be a string");
                }
                return $value === '' ? null : $value;
        }
    }

    private static function invalid(string $message): ScimException {
        return new ScimException(400, ScimErrorType::INVALID_VALUE, $message);
    }
}
