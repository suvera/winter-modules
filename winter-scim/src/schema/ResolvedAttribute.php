<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/** An attribute path matched to its definitions by {@see ResourceType::resolve()}. */
final readonly class ResolvedAttribute {
    public function __construct(
        /** The schema that holds the attribute; the core schema for `id`, `externalId` and `meta`. */
        public ResourceSchema $schema,
        public AttributeDefinition $attribute,
        public ?AttributeDefinition $subAttribute,
        /** True when the attribute sits in an extension, i.e. under the extension's URN key in the resource. */
        public bool $inExtension,
    ) {
    }

    /** The definition the path ends at. */
    public function definition(): AttributeDefinition {
        return $this->subAttribute ?? $this->attribute;
    }
}
