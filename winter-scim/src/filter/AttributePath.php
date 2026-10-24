<?php

declare(strict_types=1);

namespace dev\winterframework\scim\filter;

use dev\winterframework\scim\protocol\ScimErrorType;
use dev\winterframework\scim\protocol\ScimException;

/**
 * An attribute path (RFC 7644 §3.10): an optional schema URN, an attribute
 * name and at most one sub-attribute, e.g. `name.familyName` or
 * `urn:ietf:params:scim:schemas:extension:enterprise:2.0:User:manager.value`.
 * Names keep the client's spelling; compare them with {@see key()}, which is
 * case-insensitive as RFC 7643 §2.1 requires.
 */
final readonly class AttributePath {
    private const string NAME = '/^(?:[A-Za-z][A-Za-z0-9_-]*|\$ref)$/';

    public function __construct(
        public ?string $schema,
        public string $name,
        public ?string $subAttribute = null,
    ) {
    }

    /**
     * Parses `[urn:…:]name[.sub]`. Attribute names never hold a colon, so the
     * schema URN is everything before the last one (the URN itself may hold
     * dots, as in `…:enterprise:2.0:User`).
     */
    public static function parse(string $text, ScimErrorType $error = ScimErrorType::INVALID_FILTER): self {
        $schema = null;
        $rest = $text;
        $colon = strrpos($text, ':');
        if ($colon !== false) {
            $schema = substr($text, 0, $colon);
            $rest = substr($text, $colon + 1);
            if (stripos($schema, 'urn:') !== 0) {
                throw new ScimException(400, $error, "Invalid attribute path '$text'");
            }
        }
        $parts = explode('.', $rest);
        if (count($parts) > 2) {
            throw new ScimException(400, $error, "Attribute path '$text' is nested too deeply");
        }
        foreach ($parts as $part) {
            if (!preg_match(self::NAME, $part)) {
                throw new ScimException(400, $error, "Invalid attribute path '$text'");
            }
        }
        return new self($schema, $parts[0], $parts[1] ?? null);
    }

    /** The attribute itself, without the sub-attribute. */
    public function attribute(): self {
        return new self($this->schema, $this->name);
    }

    /** This path's attribute with $sub as its sub-attribute (a value filter's `type` under `emails`). */
    public function withSub(string $sub): self {
        return new self($this->schema, $this->name, $sub);
    }

    /** `name.sub`, lower case, without the schema: the key of a field map. */
    public function key(): string {
        return strtolower($this->subAttribute === null ? $this->name : $this->name . '.' . $this->subAttribute);
    }

    public function __toString(): string {
        $path = $this->subAttribute === null ? $this->name : $this->name . '.' . $this->subAttribute;
        return $this->schema === null ? $path : $this->schema . ':' . $path;
    }
}
