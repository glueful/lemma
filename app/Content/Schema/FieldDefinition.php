<?php

declare(strict_types=1);

namespace App\Content\Schema;

final class FieldDefinition
{
    public const TYPES = ['string', 'text', 'number', 'boolean', 'datetime', 'enum', 'reference', 'asset', 'json'];
    public const FILTER_TYPES = ['string', 'number', 'boolean', 'datetime', 'enum'];

    /** @param list<string> $enumValues */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $localized = false,
        public readonly bool $filterable = false,
        public readonly ?string $filterType = null,
        public readonly array $enumValues = [],
    ) {
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $name = isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : '';
        if ($name === '' || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new SchemaParseException("field name must match [a-z][a-z0-9_]*: '{$name}'");
        }
        $type = $raw['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new SchemaParseException("field '{$name}' has invalid type");
        }
        $filterable = (bool) ($raw['filterable'] ?? false);
        $filterType = $raw['filter_type'] ?? null;
        if ($filterable) {
            if (!is_string($filterType) || !in_array($filterType, self::FILTER_TYPES, true)) {
                throw new SchemaParseException("filterable field '{$name}' must declare a valid filter_type");
            }
        } else {
            $filterType = null;
        }
        $enum = [];
        if ($type === 'enum') {
            $enum = array_values(array_filter(
                array_map('strval', (array) ($raw['enum'] ?? [])),
                static fn(string $v): bool => $v !== ''
            ));
            if ($enum === []) {
                throw new SchemaParseException("enum field '{$name}' requires non-empty enum values");
            }
        }

        return new self(
            name: $name,
            type: $type,
            required: (bool) ($raw['required'] ?? false),
            localized: (bool) ($raw['localized'] ?? false),
            filterable: $filterable,
            filterType: $filterType,
            enumValues: $enum,
        );
    }
}
