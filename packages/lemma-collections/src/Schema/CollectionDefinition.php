<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Schema;

/**
 * Readonly value object representing a collection's full definition.
 *
 * storageMode is always 'table' in v1; fromRow() defaults a missing storage_mode to 'table'.
 */
final class CollectionDefinition
{
    /**
     * @param list<CollectionField> $fields
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $name,
        public readonly string $label,
        public readonly string $tableName,
        public readonly string $storageMode,
        public readonly array $fields,
        public readonly int $schemaVersion,
        public readonly string $status,
        public readonly AccessPolicy $accessPolicy = new AccessPolicy(
            AccessPolicy::SCOPED,
            AccessPolicy::SCOPED,
            AccessPolicy::SCOPED,
        ),
    ) {
    }

    /**
     * Hydrate from a collection_definitions database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $rawFields = $row['fields'] ?? '[]';
        if (is_string($rawFields)) {
            $rawFields = json_decode($rawFields, true) ?? [];
        }

        $fields = array_values(array_map(
            static fn (array $f): CollectionField => CollectionField::fromArray($f),
            (array) $rawFields,
        ));

        $rawPolicy = $row['access_policy'] ?? null;
        if (is_string($rawPolicy) && $rawPolicy !== '') {
            $rawPolicy = json_decode($rawPolicy, true);
        }
        $accessPolicy = is_array($rawPolicy) ? AccessPolicy::fromArray($rawPolicy) : AccessPolicy::default();

        return new self(
            uuid: (string) ($row['uuid'] ?? ''),
            name: (string) ($row['name'] ?? ''),
            label: (string) ($row['label'] ?? ''),
            tableName: (string) ($row['table_name'] ?? ''),
            storageMode: (string) ($row['storage_mode'] ?? 'table'),
            fields: $fields,
            schemaVersion: (int) ($row['schema_version'] ?? 1),
            status: (string) ($row['status'] ?? 'draft'),
            accessPolicy: $accessPolicy,
        );
    }

    /**
     * Look up a field by name; returns null when no field with that name exists.
     */
    public function field(string $name): ?CollectionField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
