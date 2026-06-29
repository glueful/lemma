<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Schema;

/**
 * Immutable value object representing a single DDL operation to apply to a collection's table.
 *
 * Allowed ops: create_table | add_field | drop_field | add_index | drop_index | drop_table
 *
 * The `destructive` flag is true when the operation may cause data loss (drop_field) or
 * requires a data pre-flight check before it can be safely applied (add_index for a unique
 * constraint, tightening nullable). Task 6 (Materializer) reads this flag to gate execution.
 */
final class SchemaChange
{
    public function __construct(
        public readonly string $op,
        public readonly ?CollectionField $field,
        public readonly bool $destructive,
    ) {
    }
}
