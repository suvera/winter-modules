<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

use DateTimeImmutable;
use dev\winterframework\scim\protocol\Keys;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\AttributeDefinition;
use dev\winterframework\scim\schema\AttributeType;
use dev\winterframework\scim\schema\ResourceType;

/**
 * Evaluates a filter against a resource in memory (RFC 7644 §3.4.2.2): the
 * PATCH applier uses it to pick the values of `members[value eq "…"]`. A
 * multi-valued attribute matches when any of its values does; strings
 * compare case-insensitively unless the attribute is caseExact.
 */
final readonly class FilterMatcher {
    public function __construct(private ResourceType $type) {
    }

    /** @param array<string, mixed> $resource */
    public function matches(FilterNode $filter, array $resource): bool {
        return match (true) {
            $filter instanceof Logical => $filter->operator === LogicalOperator::AND
                ? $this->matches($filter->left, $resource) && $this->matches($filter->right, $resource)
                : $this->matches($filter->left, $resource) || $this->matches($filter->right, $resource),
            $filter instanceof Negation => !$this->matches($filter->filter, $resource),
            $filter instanceof ValuePath => $this->anyValue($filter, $resource),
            $filter instanceof Presence, $filter instanceof Comparison => $this->leaf($filter, $this->values($filter->path, $resource), $this->definition($filter->path)),
            default => false,
        };
    }

    /**
     * Whether one value of a multi-valued complex attribute matches $filter,
     * whose paths name its sub-attributes.
     *
     * @param array<string, mixed> $value
     */
    public function matchesValue(FilterNode $filter, array $value, AttributeDefinition $attribute): bool {
        return match (true) {
            $filter instanceof Logical => $filter->operator === LogicalOperator::AND
                ? $this->matchesValue($filter->left, $value, $attribute) && $this->matchesValue($filter->right, $value, $attribute)
                : $this->matchesValue($filter->left, $value, $attribute) || $this->matchesValue($filter->right, $value, $attribute),
            $filter instanceof Negation => !$this->matchesValue($filter->filter, $value, $attribute),
            $filter instanceof Presence, $filter instanceof Comparison => $this->leaf(
                $filter,
                self::flatten([Keys::get($value, $filter->path->name)]),
                $attribute->subAttribute($filter->path->name)
                    ?? throw new ScimException(400, ScimErrorType::INVALID_FILTER, "'{$attribute->name}' has no sub-attribute '{$filter->path->name}'"),
            ),
            default => throw new ScimException(400, ScimErrorType::INVALID_FILTER, 'Value filters cannot be nested'),
        };
    }

    /** @param array<string, mixed> $resource */
    private function anyValue(ValuePath $filter, array $resource): bool {
        $attribute = $this->definition($filter->path);
        foreach ($this->container($filter->path, $resource) as $value) {
            if (is_array($value) && $this->matchesValue($filter->filter, $value, $attribute)) {
                return true;
            }
        }
        return false;
    }

    /** @param list<mixed> $values */
    private function leaf(Presence|Comparison $filter, array $values, AttributeDefinition $definition): bool {
        if ($definition->isComplex()) {
            $sub = $definition->subAttribute('value');
            if ($sub === null) {
                throw new ScimException(400, ScimErrorType::INVALID_FILTER, "'{$filter->path}' is a complex attribute: name one of its sub-attributes");
            }
            $values = self::flatten(array_map(static fn($v) => is_array($v) ? Keys::get($v, 'value') : $v, $values));
            $definition = $sub;
        }
        $values = array_values(array_filter($values, static fn($v) => $v !== null && $v !== ''));
        if ($filter instanceof Presence) {
            return $values !== [];
        }
        $expected = ComparisonValue::of($definition, $filter->operator, $filter->value, (string)$filter->path);
        if ($expected === null) {
            return ($filter->operator === CompareOperator::EQ) === ($values === []);
        }
        foreach ($values as $actual) {
            if ($this->compare($actual, $filter->operator, $expected, $definition)) {
                return true;
            }
        }
        // ne holds when nothing equals the value, including when there is no value.
        return $filter->operator === CompareOperator::NE && $values === [];
    }

    private function compare(mixed $actual, CompareOperator $op, mixed $expected, AttributeDefinition $definition): bool {
        if ($expected instanceof DateTimeImmutable) {
            $instant = ComparisonValue::instant($actual);
            if ($instant === null) {
                return false;
            }
            $cmp = $instant <=> $expected;
        } elseif (is_bool($expected)) {
            return ($actual === $expected) === ($op === CompareOperator::EQ);
        } elseif (is_int($expected) || is_float($expected)) {
            if (!is_numeric($actual)) {
                return false;
            }
            $cmp = $actual + 0 <=> $expected;
        } else {
            $a = is_scalar($actual) ? (string)$actual : '';
            $b = (string)$expected;
            if (!$definition->caseExact && $definition->type !== AttributeType::BINARY) {
                $a = mb_strtolower($a);
                $b = mb_strtolower($b);
            }
            switch ($op) {
                case CompareOperator::CO:
                    return str_contains($a, $b);
                case CompareOperator::SW:
                    return str_starts_with($a, $b);
                case CompareOperator::EW:
                    return str_ends_with($a, $b);
            }
            $cmp = strcmp($a, $b) <=> 0;
        }
        return match ($op) {
            CompareOperator::EQ => $cmp === 0,
            CompareOperator::NE => $cmp !== 0,
            CompareOperator::GT => $cmp > 0,
            CompareOperator::GE => $cmp >= 0,
            CompareOperator::LT => $cmp < 0,
            CompareOperator::LE => $cmp <= 0,
            default => false,
        };
    }

    private function definition(AttributePath $path): AttributeDefinition {
        $resolved = $this->type->resolve($path);
        if ($resolved === null) {
            throw new ScimException(400, ScimErrorType::INVALID_FILTER, "Unknown attribute '$path'");
        }
        return $resolved->definition();
    }

    /**
     * The attribute's values: a list for multi-valued attributes, one value otherwise.
     *
     * @param array<string, mixed> $resource
     * @return list<mixed>
     */
    private function container(AttributePath $path, array $resource): array {
        $resolved = $this->type->resolve($path);
        $holder = $resource;
        if ($resolved !== null && $resolved->inExtension) {
            $holder = Keys::get($resource, $resolved->schema->id);
            if (!is_array($holder)) {
                return [];
            }
        }
        $value = Keys::get($holder, $path->name);
        if ($value === null) {
            return [];
        }
        return is_array($value) && array_is_list($value) ? $value : [$value];
    }

    /**
     * @param array<string, mixed> $resource
     * @return list<mixed>
     */
    private function values(AttributePath $path, array $resource): array {
        $values = $this->container($path, $resource);
        if ($path->subAttribute === null) {
            return $values;
        }
        return self::flatten(array_map(static fn($v) => is_array($v) ? Keys::get($v, $path->subAttribute) : null, $values));
    }

    /** @return list<mixed> */
    private static function flatten(array $values): array {
        $out = [];
        foreach ($values as $value) {
            if (is_array($value) && array_is_list($value)) {
                array_push($out, ...$value);
            } else {
                $out[] = $value;
            }
        }
        return $out;
    }
}
