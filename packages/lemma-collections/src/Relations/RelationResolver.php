<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Relations;

use Glueful\Database\Connection;
use Glueful\Lemma\Collections\Exceptions\RowReferencedException;
use Glueful\Lemma\Collections\Exceptions\RowValidationException;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Lemma\Collections\Schema\CollectionField;

/**
 * Validates and resolves collection↔collection relation fields.
 *
 * ## Scope (v1)
 * Only `collection:*` targets are handled. A `content:*` target descriptor is out of scope
 * for v1 — encountering one is treated as unsupported and silently skipped (no validation,
 * no expansion). Pure collection relations always use `settings['target'] = "collection:{name}"`.
 *
 * ## Methods
 *
 * assertTargetsExist() — called by RowRepository before insert/update. For each referenced
 *   uuid in the value, it confirms the uuid exists in the target collection's table. Missing
 *   target → RowValidationException (reuses Task 8's exception class so the HTTP layer maps
 *   it to 422 uniformly).
 *
 * expand() — replaces relation field values with the resolved target rows. One level only;
 *   the expanded rows are returned as-is (their own relation fields remain raw uuids / JSON).
 *   Batch-loads to avoid N+1 queries within a single call.
 *
 * assertNotReferenced() — called by RowRepository before delete. Scans every collection
 *   definition for relation fields whose target is `collection:{target->name}`. For single
 *   relations it uses an equality check; for multi relations it searches the JSON-encoded
 *   array for the quoted uuid string (`LIKE '%"uuid"%'`). Throws RowReferencedException if
 *   any match is found.
 */
final class RelationResolver
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CollectionDefinitionRepository $definitions,
    ) {
    }

    /**
     * Assert that every uuid referenced by $value exists in the target collection's table.
     *
     * $value is the original (pre-coerce) input for the field:
     *   - single relation (multi=false) → a string uuid
     *   - multi relation (multi=true)   → a list<string> of uuids
     *
     * A null/empty value is allowed when the field is nullable.
     *
     * @param array<int, string>|string $value
     * @throws RowValidationException when any referenced uuid is absent.
     */
    public function assertTargetsExist(CollectionField $field, array|string $value): void
    {
        $target = $field->settings['target'] ?? '';

        // content:* targets are out of scope for v1 — skip silently.
        if (!str_starts_with($target, 'collection:')) {
            return;
        }

        $targetName = substr($target, strlen('collection:'));
        $targetDef  = $this->definitions->findByName($targetName);

        if ($targetDef === null) {
            // Unknown target collection — treat as a validation error so bad configs surface.
            throw RowValidationException::make([
                $field->name => sprintf(
                    "Field '%s' references an unknown collection '%s'.",
                    $field->name,
                    $targetName,
                ),
            ]);
        }

        $isMulti = !empty($field->settings['multi']);
        $uuids   = $isMulti ? (array) $value : [$value];

        foreach ($uuids as $uuid) {
            if (!is_string($uuid) || $uuid === '') {
                continue;
            }

            $exists = $this->connection
                ->table($targetDef->tableName)
                ->where('uuid', $uuid)
                ->count() > 0;

            if (!$exists) {
                throw RowValidationException::make([
                    $field->name => sprintf(
                        "Field '%s' references a non-existent row uuid '%s' in collection '%s'.",
                        $field->name,
                        $uuid,
                        $targetName,
                    ),
                ]);
            }
        }
    }

    /**
     * Replace relation field values in $rows with the resolved target rows.
     *
     * Only fields named in $expand are processed; non-relation fields and unknown names are
     * skipped. The replacement is one level deep — the returned target rows are never
     * recursively expanded (their own relation fields remain as stored uuid strings / JSON).
     *
     * Batch-loads target rows to avoid N+1 queries: for each relation field being expanded
     * all referenced uuids across the entire $rows list are collected, fetched in a single
     * IN query, then distributed back.
     *
     * Targets with `content:*` descriptors are skipped (out of scope for v1).
     *
     * @param  list<array<string, mixed>> $rows   Rows from a collection's table.
     * @param  list<string>               $expand Names of relation fields to expand.
     * @return list<array<string, mixed>>         The same rows with relation values resolved.
     */
    public function expand(CollectionDefinition $def, array $rows, array $expand): array
    {
        foreach ($expand as $fieldName) {
            $field = $def->field($fieldName);

            if ($field === null || $field->type !== 'collections.relation') {
                continue;
            }

            $target = $field->settings['target'] ?? '';
            if (!str_starts_with($target, 'collection:')) {
                continue; // content:* — out of scope for v1
            }

            $targetName = substr($target, strlen('collection:'));
            $targetDef  = $this->definitions->findByName($targetName);

            if ($targetDef === null) {
                continue;
            }

            $isMulti = !empty($field->settings['multi']);

            if ($isMulti) {
                $rows = $this->expandMulti($rows, $fieldName, $targetDef);
            } else {
                $rows = $this->expandSingle($rows, $fieldName, $targetDef);
            }
        }

        return $rows;
    }

    /**
     * Assert that no row in any collection references $uuid via a relation field whose
     * target is `collection:{$target->name}`.
     *
     * For single-relation columns the check is a simple equality match.
     * For multi-relation columns (JSON arrays) the check searches for the quoted uuid
     * string inside the JSON text (`LIKE '%"uuid"%'`), which is precise because:
     *   - UUIDs / Glueful row IDs never contain double-quote characters.
     *   - Each element of a JSON array of strings is always surrounded by `"` in the
     *     serialised representation, so `%"uuid"%` matches only an exact element,
     *     never a substring of a longer value.
     *
     * @throws RowReferencedException when any referencing row is found.
     */
    public function assertNotReferenced(CollectionDefinition $target, string $uuid): void
    {
        $allDefs = $this->definitions->all();

        foreach ($allDefs as $def) {
            foreach ($def->fields as $field) {
                if ($field->type !== 'collections.relation') {
                    continue;
                }

                $fieldTarget = $field->settings['target'] ?? '';
                if ($fieldTarget !== 'collection:' . $target->name) {
                    continue;
                }

                $isMulti = !empty($field->settings['multi']);

                if ($isMulti) {
                    // JSON array stored as text: search for the quoted uuid element.
                    $count = $this->connection
                        ->table($def->tableName)
                        ->where($field->name, 'LIKE', '%"' . $uuid . '"%')
                        ->count();
                } else {
                    $count = $this->connection
                        ->table($def->tableName)
                        ->where($field->name, $uuid)
                        ->count();
                }

                if ($count > 0) {
                    throw RowReferencedException::forUuid(
                        $target->name,
                        $uuid,
                        $def->name,
                        $field->name,
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------------

    /**
     * Expand a single-relation field across all rows in one batch load.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function expandSingle(array $rows, string $fieldName, CollectionDefinition $targetDef): array
    {
        // Collect all unique referenced uuids across every row.
        $uuids = [];
        foreach ($rows as $row) {
            $raw = $row[$fieldName] ?? null;
            if (is_string($raw) && $raw !== '') {
                $uuids[$raw] = true;
            }
        }

        if ($uuids === []) {
            return $rows;
        }

        $targetRows = $this->connection
            ->table($targetDef->tableName)
            ->whereIn('uuid', array_keys($uuids))
            ->get();

        /** @var array<string, array<string, mixed>> $byUuid */
        $byUuid = [];
        foreach ($targetRows as $tr) {
            $byUuid[(string) $tr['uuid']] = $tr;
        }

        foreach ($rows as &$row) {
            $raw = $row[$fieldName] ?? null;
            if (is_string($raw) && $raw !== '' && isset($byUuid[$raw])) {
                $row[$fieldName] = $byUuid[$raw];
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Expand a multi-relation field (JSON-encoded uuid array) across all rows in one batch load.
     *
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function expandMulti(array $rows, string $fieldName, CollectionDefinition $targetDef): array
    {
        // Collect all unique referenced uuids across every row.
        $uuids = [];
        foreach ($rows as $row) {
            $raw = $row[$fieldName] ?? null;
            if ($raw === null) {
                continue;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $u) {
                if (is_string($u) && $u !== '') {
                    $uuids[$u] = true;
                }
            }
        }

        if ($uuids === []) {
            return $rows;
        }

        $targetRows = $this->connection
            ->table($targetDef->tableName)
            ->whereIn('uuid', array_keys($uuids))
            ->get();

        /** @var array<string, array<string, mixed>> $byUuid */
        $byUuid = [];
        foreach ($targetRows as $tr) {
            $byUuid[(string) $tr['uuid']] = $tr;
        }

        foreach ($rows as &$row) {
            $raw = $row[$fieldName] ?? null;
            if ($raw === null) {
                $row[$fieldName] = [];
                continue;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            if (!is_array($decoded)) {
                $row[$fieldName] = [];
                continue;
            }
            $row[$fieldName] = array_values(array_filter(
                array_map(static fn (string $u): ?array => $byUuid[$u] ?? null, $decoded),
                static fn (?array $v): bool => $v !== null,
            ));
        }
        unset($row);

        return $rows;
    }
}
