<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

use DateTimeImmutable;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\AttributeType;
use dev\winterframework\scim\schema\ResolvedAttribute;
use dev\winterframework\scim\schema\ResourceType;

/**
 * Turns a filter into a DQL condition over the caller's entities, the role
 * MysqlFilterConverter plays in suvera/scim2-sdk. The field map says
 * which DQL expression holds each attribute; an attribute the map lacks is
 * a 400 `invalidFilter`, never silently dropped.
 *
 *   $where = new DqlFilterConverter(ResourceType::user(), [
 *       'username' => 'u.email', 'externalid' => 'u.externalId',
 *       'emails.value' => 'u.email', 'meta.lastmodified' => 'u.updatedAt',
 *   ])->convert(FilterParser::parse($filter));
 *   $qb->andWhere($where->dql); foreach ($where->parameters as $k => $v) $qb->setParameter($k, $v);
 *
 * Keys are lower case: `name` or `name.sub` for core attributes, and
 * `<extension urn>:name[.sub]` for extension attributes. A multi-valued
 * attribute named without a sub-attribute means its `value` (`emails co "x"`).
 *
 * Case: attributes with caseExact false are compared as they are, so their
 * columns must use a case-insensitive collation (MySQL's utf8mb4 `_ci` default);
 * wrapping them in LOWER() would only defeat the index.
 */
final class DqlFilterConverter {
    private int $counter = 0;
    /** @var array<string, mixed> */
    private array $parameters = [];

    /** @param array<string, string> $fields */
    public function __construct(
        private readonly ResourceType $type,
        private readonly array $fields,
        private readonly string $parameterPrefix = 'scim',
    ) {
    }

    public function convert(FilterNode $filter): DqlFilter {
        $this->counter = 0;
        $this->parameters = [];
        $dql = $this->node($filter, null);
        return new DqlFilter($dql, $this->parameters);
    }

    private function node(FilterNode $node, ?AttributePath $parent): string {
        return match (true) {
            $node instanceof Logical => '(' . $this->node($node->left, $parent) . ' ' . strtoupper($node->operator->value)
                . ' ' . $this->node($node->right, $parent) . ')',
            $node instanceof Negation => 'NOT (' . $this->node($node->filter, $parent) . ')',
            $node instanceof ValuePath => $this->node($node->filter, $node->path),
            $node instanceof Presence => $this->presence($this->path($node->path, $parent)),
            $node instanceof Comparison => $this->comparison($node, $this->path($node->path, $parent)),
            default => throw new ScimException(400, ScimErrorType::INVALID_FILTER, 'Unsupported filter'),
        };
    }

    private function path(AttributePath $path, ?AttributePath $parent): AttributePath {
        return $parent === null ? $path : $parent->withSub($path->name);
    }

    private function presence(AttributePath $path): string {
        [$field, $resolved] = $this->field($path);
        if ($resolved->definition()->type === AttributeType::STRING) {
            return "($field IS NOT NULL AND $field <> '')";
        }
        return "$field IS NOT NULL";
    }

    private function comparison(Comparison $node, AttributePath $path): string {
        [$field, $resolved] = $this->field($path);
        $value = ComparisonValue::of($resolved->definition(), $node->operator, $node->value, (string)$path);
        if ($value === null) {
            return $node->operator === CompareOperator::EQ ? "$field IS NULL" : "$field IS NOT NULL";
        }
        if ($value instanceof DateTimeImmutable) {
            $value = $value->format('Y-m-d H:i:s');
        }
        $op = $node->operator;
        if (in_array($op, [CompareOperator::CO, CompareOperator::SW, CompareOperator::EW], true)) {
            $like = strtr((string)$value, ['!' => '!!', '%' => '!%', '_' => '!_']);
            $like = match ($op) {
                CompareOperator::SW => $like . '%',
                CompareOperator::EW => '%' . $like,
                default => '%' . $like . '%',
            };
            return "$field LIKE " . $this->bind($like) . " ESCAPE '!'";
        }
        $param = $this->bind($value);
        return match ($op) {
            CompareOperator::EQ => "$field = $param",
            CompareOperator::NE => "($field <> $param OR $field IS NULL)",
            CompareOperator::GT => "$field > $param",
            CompareOperator::GE => "$field >= $param",
            CompareOperator::LT => "$field < $param",
            CompareOperator::LE => "$field <= $param",
        };
    }

    /** @return array{0: string, 1: ResolvedAttribute} */
    private function field(AttributePath $path): array {
        $resolved = $this->type->resolve($path);
        if ($resolved === null) {
            throw new ScimException(400, ScimErrorType::INVALID_FILTER, "Unknown attribute '$path'");
        }
        if ($resolved->subAttribute === null && $resolved->attribute->isComplex()) {
            $value = $resolved->attribute->subAttribute('value');
            if ($value === null) {
                throw new ScimException(400, ScimErrorType::INVALID_FILTER, "'$path' is a complex attribute: name one of its sub-attributes");
            }
            $path = $path->withSub('value');
            $resolved = new ResolvedAttribute($resolved->schema, $resolved->attribute, $value, $resolved->inExtension);
        }
        $key = $resolved->inExtension ? strtolower($resolved->schema->id) . ':' . $path->key() : $path->key();
        $field = $this->fields[$key] ?? null;
        if ($field === null) {
            throw new ScimException(400, ScimErrorType::INVALID_FILTER, "Filtering on '$path' is not supported");
        }
        return [$field, $resolved];
    }

    private function bind(mixed $value): string {
        $name = $this->parameterPrefix . '_' . ++$this->counter;
        $this->parameters[$name] = $value;
        return ':' . $name;
    }
}
