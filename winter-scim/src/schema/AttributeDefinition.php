<?php

declare(strict_types=1);

namespace dev\winterframework\scim\schema;

/**
 * One attribute of a SCIM schema (RFC 7643 §7), with the RFC's defaults.
 * Names are matched case-insensitively.
 */
final readonly class AttributeDefinition {
    /**
     * @param list<AttributeDefinition> $subAttributes
     * @param list<string> $canonicalValues
     * @param list<string> $referenceTypes
     */
    public function __construct(
        public string $name,
        public AttributeType $type = AttributeType::STRING,
        public bool $multiValued = false,
        public string $description = '',
        public bool $required = false,
        public bool $caseExact = false,
        public Mutability $mutability = Mutability::READ_WRITE,
        public Returned $returned = Returned::DEFAULT,
        public Uniqueness $uniqueness = Uniqueness::NONE,
        public array $subAttributes = [],
        public array $canonicalValues = [],
        public array $referenceTypes = [],
    ) {
    }

    public function subAttribute(string $name): ?AttributeDefinition {
        foreach ($this->subAttributes as $sub) {
            if (strcasecmp($sub->name, $name) === 0) {
                return $sub;
            }
        }
        return null;
    }

    public function isComplex(): bool {
        return $this->type === AttributeType::COMPLEX;
    }

    /** @return array<string, mixed> the RFC 7643 §7 representation */
    public function toArray(): array {
        $out = [
            'name' => $this->name,
            'type' => $this->type->value,
            'multiValued' => $this->multiValued,
            'description' => $this->description,
            'required' => $this->required,
        ];
        if ($this->subAttributes !== []) {
            $out['subAttributes'] = array_map(static fn(AttributeDefinition $a) => $a->toArray(), $this->subAttributes);
        }
        if ($this->canonicalValues !== []) {
            $out['canonicalValues'] = $this->canonicalValues;
        }
        if (!$this->isComplex()) {
            $out['caseExact'] = $this->caseExact;
        }
        $out['mutability'] = $this->mutability->value;
        $out['returned'] = $this->returned->value;
        if (!$this->isComplex()) {
            $out['uniqueness'] = $this->uniqueness->value;
        }
        if ($this->referenceTypes !== []) {
            $out['referenceTypes'] = $this->referenceTypes;
        }
        return $out;
    }
}
