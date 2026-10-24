<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

use DateTimeImmutable;
use DateTimeZone;
use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;
use dev\winterframework\scim\schema\AttributeDefinition;
use dev\winterframework\scim\schema\AttributeType;
use Throwable;

/**
 * Checks a filter value against the attribute's type (RFC 7644 §3.4.2.2) and
 * converts it: dateTime attributes compare as instants (by the schema's type,
 * never by what the string looks like), booleans only take eq/ne, and
 * complex attributes cannot be compared at all.
 */
final class ComparisonValue {
    public static function of(AttributeDefinition $definition, CompareOperator $operator, mixed $value, string $path): mixed {
        if ($value === null) {
            return null;
        }
        $ordering = in_array($operator, [CompareOperator::GT, CompareOperator::LT, CompareOperator::GE, CompareOperator::LE], true);
        $substring = in_array($operator, [CompareOperator::CO, CompareOperator::SW, CompareOperator::EW], true);
        switch ($definition->type) {
            case AttributeType::COMPLEX:
                throw self::invalid("'$path' is a complex attribute and cannot be compared");
            case AttributeType::BOOLEAN:
                if (!is_bool($value) || $ordering || $substring) {
                    throw self::invalid("'$path' is a boolean: use eq or ne with true or false");
                }
                return $value;
            case AttributeType::INTEGER:
            case AttributeType::DECIMAL:
                if (!is_int($value) && !is_float($value)) {
                    throw self::invalid("'$path' is a number");
                }
                if ($substring) {
                    throw self::invalid("'$path' is a number and cannot take '{$operator->value}'");
                }
                return $value;
            case AttributeType::DATE_TIME:
                if ($substring) {
                    return (string)$value;
                }
                return self::instant($value) ?? throw self::invalid("'$path' needs an xsd:dateTime value");
            case AttributeType::BINARY:
                if ($ordering) {
                    throw self::invalid("'$path' is binary and cannot be ordered");
                }
                return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
            default:
                return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
    }

    /** An xsd:dateTime as a UTC instant, or null when it is not one. */
    public static function instant(mixed $value): ?DateTimeImmutable {
        if ($value instanceof DateTimeImmutable) {
            return $value->setTimezone(new DateTimeZone('UTC'));
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})?$/i', $value)) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private static function invalid(string $message): ScimException {
        return new ScimException(400, ScimErrorType::INVALID_FILTER, $message);
    }
}
