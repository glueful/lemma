<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Schema;

use Glueful\Lemma\Collections\Exceptions\BlockedSchemaChangeException;

/**
 * Pure-logic DDL planner: diffs a CollectionDefinition against its current state
 * and emits the list of SchemaChange operations required to converge the schema.
 *
 * Rules (v1):
 *  - planCreate always emits exactly one `create_table` op.
 *  - planAlter diffs field lists by name:
 *      · name only in $next       → add_field (destructive: false)
 *      · name only in $current    → drop_field (destructive: true)
 *      · same name, different type → throws BlockedSchemaChangeException (retype is blocked)
 *      · same name, unique/index setting changed → add_index / drop_index
 *  - Adding a unique index (false→true) is flagged destructive: true (needs data pre-flight).
 *  - Index ops for brand-new fields are NOT emitted separately; the add_field op already
 *    carries the field's settings; the materializer creates the index with the column.
 */
final class DdlPlanner
{
    /**
     * Plan the operations required to create a collection's table from scratch.
     *
     * @return list<SchemaChange>
     */
    public function planCreate(CollectionDefinition $definition): array
    {
        return [new SchemaChange('create_table', null, false)];
    }

    /**
     * Plan the operations required to evolve an existing table from $current to $next.
     *
     * @return list<SchemaChange>
     *
     * @throws BlockedSchemaChangeException when a field present in both definitions has
     *                                       a different type (retype is not allowed in v1).
     */
    public function planAlter(CollectionDefinition $current, CollectionDefinition $next): array
    {
        /** @var array<string, CollectionField> $currentMap */
        $currentMap = [];
        foreach ($current->fields as $field) {
            $currentMap[$field->name] = $field;
        }

        /** @var array<string, CollectionField> $nextMap */
        $nextMap = [];
        foreach ($next->fields as $field) {
            $nextMap[$field->name] = $field;
        }

        // Detect retypes before emitting any ops — fail fast on blocked changes.
        foreach ($nextMap as $name => $nextField) {
            if (!isset($currentMap[$name])) {
                continue;
            }
            $currentField = $currentMap[$name];
            if ($currentField->type !== $nextField->type) {
                throw BlockedSchemaChangeException::retype($name, $currentField->type, $nextField->type);
            }
        }

        $ops = [];

        // Fields removed in $next → destructive drop.
        foreach ($currentMap as $name => $field) {
            if (!isset($nextMap[$name])) {
                $ops[] = new SchemaChange('drop_field', $field, true);
            }
        }

        // Fields added in $next → non-destructive add.
        // Index ops are NOT emitted separately for new fields; the materializer
        // creates the index alongside the column using the field's settings.
        foreach ($nextMap as $name => $field) {
            if (!isset($currentMap[$name])) {
                $ops[] = new SchemaChange('add_field', $field, false);
            }
        }

        // Index changes on fields present in both definitions.
        foreach ($nextMap as $name => $nextField) {
            if (!isset($currentMap[$name])) {
                continue; // already handled as add_field above
            }
            $currentField = $currentMap[$name];

            $ops = array_merge($ops, $this->diffIndexOps($currentField, $nextField));
        }

        return $ops;
    }

    /**
     * Produce add_index / drop_index ops for a field whose name and type are unchanged.
     *
     * @return list<SchemaChange>
     */
    private function diffIndexOps(CollectionField $current, CollectionField $next): array
    {
        $ops = [];

        $currentUnique = !empty($current->settings['unique']);
        $nextUnique    = !empty($next->settings['unique']);

        if (!$currentUnique && $nextUnique) {
            // Adding a unique constraint requires a data pre-flight (destructive: true).
            $ops[] = new SchemaChange('add_index', $next, true);
        } elseif ($currentUnique && !$nextUnique) {
            $ops[] = new SchemaChange('drop_index', $next, false);
        }

        $currentIndex = !empty($current->settings['index']);
        $nextIndex    = !empty($next->settings['index']);

        if (!$currentIndex && $nextIndex) {
            $ops[] = new SchemaChange('add_index', $next, false);
        } elseif ($currentIndex && !$nextIndex) {
            $ops[] = new SchemaChange('drop_index', $next, false);
        }

        return $ops;
    }
}
