<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections;

use Glueful\Database\Connection;
use Glueful\Lemma\Collections\Exceptions\CollectionValidationException;
use Glueful\Lemma\Collections\Exceptions\DestructiveConfirmationRequiredException;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Lemma\Collections\Schema\CollectionField;
use Glueful\Lemma\Collections\Schema\DdlPlanner;
use Glueful\Lemma\Collections\Schema\SchemaChange;
use Glueful\Lemma\Collections\Schema\SchemaMaterializer;
use Glueful\Lemma\Collections\Support\PublicId;

/**
 * Orchestrates the full lifecycle of a collection: create, evolve (add field / add index /
 * remove index), and guarded destruction (drop field / drop collection).
 *
 * ## Table name derivation
 *
 * Physical table names are deterministic and collision-resistant:
 *   table_name = 'collection_' . substr(hash('sha256', $name), 0, 12)
 *
 * The 12-hex-char suffix gives 48 bits of address space — more than sufficient for the
 * small number of user-defined collections in a single Lemma instance while keeping names
 * short enough to fit the 80-char `table_name` column.
 *
 * ## Empty-table light path for destructive operations
 *
 * dropField() and dropCollection() require an explicit confirmation token when the data
 * table is non-empty.  When the table has zero rows, the confirmation is waived — this
 * avoids friction during schema iteration before any data has been ingested.
 *
 * ## schema_version
 *
 * Every accepted change bumps schema_version by 1. The version is stored on the
 * CollectionDefinition row and serves as a lightweight audit signal.
 *
 * ## What this manager does NOT do
 *
 * It is never invoked for capability enable/disable.  Migrations and capability
 * registration are handled by LemmaCollectionsServiceProvider.
 */
final class CollectionManager
{
    /**
     * System columns added to every collection table by SchemaMaterializer.
     * Field names that collide with these must be rejected at the create() gate.
     *
     * @var list<string>
     */
    private const SYSTEM_COLUMNS = [
        'id',
        'uuid',
        'created_at',
        'updated_at',
        'created_by_type',
        'created_by_id',
        'updated_by_type',
        'updated_by_id',
    ];

    public function __construct(
        private readonly CollectionDefinitionRepository $repo,
        private readonly DdlPlanner $planner,
        private readonly SchemaMaterializer $materializer,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Create a new collection: validate, persist the definition, and materialize the table.
     *
     * @param array<string, mixed> $payload
     *   Required keys: name (string), fields (list<array>)
     *   Optional keys: label (string), storage_mode (string — only 'table' accepted)
     *
     * @throws CollectionValidationException when name format is invalid, storage_mode is
     *                                        not 'table', or any field name conflicts with
     *                                        a system column.
     */
    public function create(array $payload, string $actorType, ?string $actorId): CollectionDefinition
    {
        $this->validateCreate($payload);

        $name      = (string) $payload['name'];
        $label     = isset($payload['label']) ? (string) $payload['label'] : $this->deriveLabel($name);
        $tableName = $this->deriveTableName($name);
        $uuid      = PublicId::generate('col');

        /** @var list<CollectionField> $fields */
        $fields = array_values(array_map(
            static fn (array $f): CollectionField => CollectionField::fromArray($f),
            (array) ($payload['fields'] ?? []),
        ));

        $def = new CollectionDefinition(
            uuid: $uuid,
            name: $name,
            label: $label,
            tableName: $tableName,
            storageMode: 'table',
            fields: $fields,
            schemaVersion: 1,
            status: 'draft',
        );

        $this->repo->insert($def);

        $this->materializer->apply(
            $def,
            $this->planner->planCreate($def),
            $actorType,
            $actorId,
        );

        return $def;
    }

    /**
     * Add a field to an existing collection.
     *
     * @param array<string, mixed> $field  Field descriptor (name, type, settings).
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function addField(
        string $name,
        array $field,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current  = $this->loadOrFail($name);
        $newField = CollectionField::fromArray($field);

        $next = $this->rebuildWith($current, [...$current->fields, $newField]);
        $ops  = $this->planner->planAlter($current, $next);

        $this->materializer->apply($next, $ops, $actorType, $actorId);
        $this->repo->update($next);

        return $next;
    }

    /**
     * Add an index (plain or unique) to an existing field.
     *
     * @param array<string, mixed> $settings  Index settings to merge into the field
     *                                         (e.g. ['index' => true] or ['unique' => true]).
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function addIndex(
        string $name,
        string $field,
        array $settings,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);

        $updatedFields = array_map(
            static fn (CollectionField $f): CollectionField => $f->name === $field
                ? new CollectionField($f->name, $f->type, array_merge($f->settings, $settings))
                : $f,
            $current->fields,
        );

        $next = $this->rebuildWith($current, array_values($updatedFields));
        $ops  = $this->planner->planAlter($current, $next);

        $this->materializer->apply($next, $ops, $actorType, $actorId);
        $this->repo->update($next);

        return $next;
    }

    /**
     * Remove an index (plain or unique) from an existing field.
     *
     * @throws \DomainException when no collection with $name exists.
     */
    public function removeIndex(
        string $name,
        string $field,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);

        $indexKeys     = ['index' => true, 'unique' => true];
        $updatedFields = array_map(
            static fn (CollectionField $f): CollectionField => $f->name === $field
                ? new CollectionField(
                    $f->name,
                    $f->type,
                    array_diff_key($f->settings, $indexKeys),
                )
                : $f,
            $current->fields,
        );

        $next = $this->rebuildWith($current, array_values($updatedFields));
        $ops  = $this->planner->planAlter($current, $next);

        $this->materializer->apply($next, $ops, $actorType, $actorId);
        $this->repo->update($next);

        return $next;
    }

    /**
     * Drop a field from an existing collection.
     *
     * When the data table has existing rows, $opts['confirm'] must equal $field exactly.
     * When the table is empty the confirmation is waived (empty-table light path).
     *
     * @param array<string, mixed> $opts  Pass ['confirm' => $field] to acknowledge data loss.
     *
     * @throws DestructiveConfirmationRequiredException when the table has rows and
     *                                                   confirmation is absent or wrong.
     * @throws \DomainException when no collection with $name exists.
     */
    public function dropField(
        string $name,
        string $field,
        array $opts,
        string $actorType,
        ?string $actorId,
    ): CollectionDefinition {
        $current = $this->loadOrFail($name);

        if (!$this->isTableEmpty($current->tableName)) {
            $confirm = $opts['confirm'] ?? null;
            if ($confirm !== $field) {
                throw DestructiveConfirmationRequiredException::forField($field, $name);
            }
        }

        $updatedFields = array_values(array_filter(
            $current->fields,
            static fn (CollectionField $f): bool => $f->name !== $field,
        ));

        $next = $this->rebuildWith($current, $updatedFields);
        $ops  = $this->planner->planAlter($current, $next);

        $this->materializer->apply($next, $ops, $actorType, $actorId);
        $this->repo->update($next);

        return $next;
    }

    /**
     * Drop an entire collection: verify confirmation, drop the physical table, delete the
     * definition row.
     *
     * When the data table has existing rows, $opts['confirm'] must equal $name exactly.
     * When the table is empty the confirmation is waived (empty-table light path).
     *
     * @param array<string, mixed> $opts  Pass ['confirm' => $name] to acknowledge data loss.
     *
     * @throws DestructiveConfirmationRequiredException when the table has rows and
     *                                                   confirmation is absent or wrong.
     * @throws \DomainException when no collection with $name exists.
     */
    public function dropCollection(
        string $name,
        array $opts,
        string $actorType,
        ?string $actorId,
    ): void {
        $current = $this->loadOrFail($name);

        if (!$this->isTableEmpty($current->tableName)) {
            $confirm = $opts['confirm'] ?? null;
            if ($confirm !== $name) {
                throw DestructiveConfirmationRequiredException::forCollection($name);
            }
        }

        $this->materializer->apply(
            $current,
            [new SchemaChange('drop_table', null, true)],
            $actorType,
            $actorId,
        );

        $this->repo->delete($current->uuid);
    }

    // ----------------------------------------------------------------- private

    /**
     * @param array<string, mixed> $payload
     *
     * @throws CollectionValidationException
     */
    private function validateCreate(array $payload): void
    {
        $errors = [];

        // storage_mode: absent defaults to 'table'; any other value is rejected.
        $storageMode = isset($payload['storage_mode']) ? (string) $payload['storage_mode'] : 'table';
        if ($storageMode !== 'table') {
            $errors['storage_mode'] = 'Only table storage is supported in v1.';
        }

        // name: required, must match the safe-identifier pattern.
        $name = isset($payload['name']) ? (string) $payload['name'] : '';
        if ($name === '' || preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            $errors['name'] = 'Name must start with a lowercase letter and contain only [a-z0-9_].';
        }

        // Fail early on storage_mode / name errors before inspecting fields.
        if ($errors !== []) {
            throw CollectionValidationException::make($errors);
        }

        // fields: validate each field's name against system columns.
        foreach ((array) ($payload['fields'] ?? []) as $i => $fieldData) {
            $fieldName = isset($fieldData['name']) ? (string) $fieldData['name'] : '';
            if (in_array($fieldName, self::SYSTEM_COLUMNS, true)) {
                $errors["fields.{$i}.name"] = sprintf(
                    "Field name '%s' conflicts with a system column.",
                    $fieldName,
                );
            }
        }

        if ($errors !== []) {
            throw CollectionValidationException::make($errors);
        }
    }

    /**
     * Derive a human-readable label from a snake_case collection name.
     */
    private function deriveLabel(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }

    /**
     * Derive the deterministic physical table name for a collection.
     *
     * Algorithm: 'collection_' + first 12 hex chars of SHA-256 of the collection name.
     * This gives 48 bits of uniqueness space — sufficient for any realistic number of
     * user-defined collections in a single Lemma deployment.
     */
    private function deriveTableName(string $name): string
    {
        return 'collection_' . substr(hash('sha256', $name), 0, 12);
    }

    /**
     * Load a CollectionDefinition by name, throwing if it does not exist.
     *
     * @throws \DomainException
     */
    private function loadOrFail(string $name): CollectionDefinition
    {
        $def = $this->repo->findByName($name);
        if ($def === null) {
            throw new \DomainException(sprintf("Collection '%s' not found.", $name));
        }

        return $def;
    }

    /**
     * Return true when the data table has zero rows.
     *
     * Used by the empty-table light path to waive destructive-op confirmation.
     */
    private function isTableEmpty(string $tableName): bool
    {
        return $this->connection->table($tableName)->count() === 0;
    }

    /**
     * Build the "next" CollectionDefinition from the current one by replacing the field
     * list and bumping schema_version.
     *
     * @param list<CollectionField> $fields
     */
    private function rebuildWith(CollectionDefinition $current, array $fields): CollectionDefinition
    {
        return new CollectionDefinition(
            uuid: $current->uuid,
            name: $current->name,
            label: $current->label,
            tableName: $current->tableName,
            storageMode: $current->storageMode,
            fields: $fields,
            schemaVersion: $current->schemaVersion + 1,
            status: $current->status,
        );
    }
}
