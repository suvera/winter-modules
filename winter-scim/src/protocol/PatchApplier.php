<?php

declare(strict_types=1);

namespace dev\winterframework\scim\protocol;

use dev\winterframework\scim\filter\AttributePath;
use dev\winterframework\scim\filter\FilterMatcher;
use dev\winterframework\scim\filter\PatchPath;
use dev\winterframework\scim\schema\AttributeDefinition;
use dev\winterframework\scim\schema\Mutability;
use dev\winterframework\scim\schema\ResolvedAttribute;
use dev\winterframework\scim\schema\ResourceType;

/**
 * Applies a PATCH request to a resource's SCIM representation (RFC 7644
 * §3.5.2). The result still has to pass {@see ResourceValidator}, which
 * checks types, required and immutable attributes.
 *
 * Beyond the RFC's own forms it takes what Entra ID and Okta send: a
 * path-less value whose keys are paths (`{"name.givenName": "x"}`) or
 * extension URNs, and `remove` on `members` with a value listing the members
 * to drop. A `remove` whose filter matches nothing is a no-op (Entra removes
 * members that are already gone); a `replace` whose filter matches nothing
 * is a 400 `noTarget`, as the RFC says.
 */
final readonly class PatchApplier {
    private FilterMatcher $matcher;

    public function __construct(private ResourceType $type) {
        $this->matcher = new FilterMatcher($type);
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public function apply(array $resource, PatchRequest $request): array {
        foreach ($request->operations as $operation) {
            if ($operation->path !== null) {
                $resource = $this->at($resource, $operation->op, $operation->path, $operation->value);
                continue;
            }
            foreach ($operation->value as $key => $value) {
                if (strcasecmp((string)$key, 'schemas') === 0) {
                    continue;
                }
                $extension = $this->type->schemaById((string)$key);
                if ($extension !== null && $extension !== $this->type->schema) {
                    if (!is_array($value)) {
                        throw new ScimException(400, ScimErrorType::INVALID_VALUE, "'$key' must be an object");
                    }
                    foreach ($value as $name => $item) {
                        $path = new PatchPath(AttributePath::parse($extension->id . ':' . $name, ScimErrorType::INVALID_PATH));
                        $resource = $this->at($resource, $operation->op, $path, $item);
                    }
                    continue;
                }
                $resource = $this->at($resource, $operation->op, new PatchPath(AttributePath::parse((string)$key, ScimErrorType::INVALID_PATH)), $value);
            }
        }
        return $resource;
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function at(array $resource, PatchOp $op, PatchPath $path, mixed $value): array {
        $resolved = $this->type->resolve($path->attribute, ScimErrorType::INVALID_PATH)
            ?? throw new ScimException(400, ScimErrorType::INVALID_PATH, "Unknown attribute '{$path->attribute}'");
        $attribute = $resolved->attribute;
        $sub = $resolved->subAttribute;
        if ($path->subAttribute !== null) {
            if ($path->filter === null || !$attribute->isComplex()) {
                throw new ScimException(400, ScimErrorType::INVALID_PATH, "Invalid path '{$path->attribute}'");
            }
            $sub = $attribute->subAttribute($path->subAttribute)
                ?? throw new ScimException(400, ScimErrorType::INVALID_PATH, "'{$attribute->name}' has no sub-attribute '{$path->subAttribute}'");
        }
        foreach ([$attribute, $sub] as $definition) {
            if ($definition !== null && $definition->mutability === Mutability::READ_ONLY) {
                throw new ScimException(400, ScimErrorType::MUTABILITY, "'{$definition->name}' is read-only");
            }
        }

        [$holder, $holderKey] = $this->holder($resource, $resolved, $op);
        if ($holder === null) {
            return $resource;
        }
        $key = Keys::find($holder, $attribute->name);
        $current = $key === null ? null : $holder[$key];
        if ($key !== null) {
            unset($holder[$key]);
        }

        if ($path->filter !== null) {
            if (!$attribute->multiValued || !$attribute->isComplex()) {
                throw new ScimException(400, ScimErrorType::INVALID_PATH, "'{$attribute->name}' is not a multi-valued complex attribute");
            }
            $next = $this->filtered(is_array($current) ? array_values($current) : [], $op, $path, $attribute, $sub, $value);
        } elseif ($sub !== null) {
            $next = $this->subAttribute($current, $op, $attribute, $sub, $value);
        } else {
            $next = $this->whole($current, $op, $attribute, $value);
        }

        if ($next !== null && $next !== []) {
            $holder[$attribute->name] = $next;
        }
        if ($holderKey === null) {
            return $holder;
        }
        unset($resource[Keys::find($resource, $holderKey) ?? $holderKey]);
        if ($holder !== []) {
            $resource[$holderKey] = $holder;
        }
        return $resource;
    }

    /**
     * The object that holds the attribute: the resource, or its extension object.
     *
     * @return array{0: array<string, mixed>|null, 1: string|null} null holder when there is nothing to remove
     */
    private function holder(array $resource, ResolvedAttribute $resolved, PatchOp $op): array {
        if (!$resolved->inExtension) {
            return [$resource, null];
        }
        $holder = Keys::get($resource, $resolved->schema->id);
        if (!is_array($holder)) {
            if ($op === PatchOp::REMOVE) {
                return [null, null];
            }
            $holder = [];
        }
        return [$holder, $resolved->schema->id];
    }

    private function whole(mixed $current, PatchOp $op, AttributeDefinition $attribute, mixed $value): mixed {
        if ($op === PatchOp::REMOVE) {
            if ($attribute->multiValued && is_array($value) && is_array($current)) {
                return $this->without($current, $value);
            }
            return null;
        }
        if ($attribute->multiValued) {
            $values = self::listOf($value);
            if ($op === PatchOp::REPLACE) {
                return $values;
            }
            $current = is_array($current) ? array_values($current) : [];
            foreach ($values as $item) {
                if (!$this->contains($current, $item)) {
                    $current[] = $item;
                }
            }
            return $current;
        }
        if ($attribute->isComplex() && is_array($value) && is_array($current)) {
            // Both add and replace merge the given sub-attributes (RFC 7644 §3.5.2.1, §3.5.2.3).
            foreach ($value as $name => $item) {
                $existing = Keys::find($current, (string)$name);
                if ($existing !== null) {
                    unset($current[$existing]);
                }
                $current[$name] = $item;
            }
            return $current;
        }
        return $value;
    }

    private function subAttribute(mixed $current, PatchOp $op, AttributeDefinition $attribute, AttributeDefinition $sub, mixed $value): mixed {
        $set = static function (array $object) use ($op, $sub, $value): array {
            $key = Keys::find($object, $sub->name);
            if ($key !== null) {
                unset($object[$key]);
            }
            if ($op !== PatchOp::REMOVE) {
                $object[$sub->name] = $value;
            }
            return $object;
        };
        if ($attribute->multiValued) {
            return is_array($current) ? array_map(static fn($item) => is_array($item) ? $set($item) : $item, array_values($current)) : null;
        }
        return $set(is_array($current) ? $current : []);
    }

    /** @param list<mixed> $values */
    private function filtered(array $values, PatchOp $op, PatchPath $path, AttributeDefinition $attribute, ?AttributeDefinition $sub, mixed $value): array {
        $matched = false;
        $out = [];
        foreach ($values as $item) {
            if (!is_array($item) || !$this->matcher->matchesValue($path->filter, $item, $attribute)) {
                $out[] = $item;
                continue;
            }
            $matched = true;
            if ($sub !== null) {
                $key = Keys::find($item, $sub->name);
                if ($key !== null) {
                    unset($item[$key]);
                }
                if ($op !== PatchOp::REMOVE) {
                    $item[$sub->name] = $value;
                }
                $out[] = $item;
            } elseif ($op !== PatchOp::REMOVE) {
                if (!is_array($value) || array_is_list($value)) {
                    throw new ScimException(400, ScimErrorType::INVALID_VALUE, "The value for '{$attribute->name}[…]' must be an object");
                }
                $out[] = $value;
            }
        }
        if (!$matched && $op !== PatchOp::REMOVE) {
            throw new ScimException(400, ScimErrorType::NO_TARGET, "No value of '{$attribute->name}' matches the filter");
        }
        return $out;
    }

    /** $current minus the values listed in $remove (compared by `value` when they have one). */
    private function without(array $current, mixed $remove): array {
        $remove = self::listOf($remove);
        return array_values(array_filter($current, fn($item) => !$this->contains($remove, $item)));
    }

    private function contains(array $values, mixed $item): bool {
        $id = is_array($item) ? Keys::get($item, 'value') : $item;
        foreach ($values as $value) {
            $other = is_array($value) ? Keys::get($value, 'value') : $value;
            if ($id !== null && $other !== null ? (string)$id === (string)$other : $value == $item) {
                return true;
            }
        }
        return false;
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array {
        if ($value === null) {
            return [];
        }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }
}
