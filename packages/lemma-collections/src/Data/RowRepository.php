<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Data;

use Glueful\Database\Connection;
use Glueful\Lemma\Collections\Exceptions\RowNotFoundException;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Lemma\Collections\Support\PublicId;

/**
 * CRUD gateway for rows stored in a collection's materialized table.
 *
 * All writes go through RowValidator before touching the database.
 * System columns (uuid, created_at, updated_at, created_by_*, updated_by_*)
 * are managed here and never exposed to the caller's $input.
 */
final class RowRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RowValidator $validator,
    ) {
    }

    /**
     * Insert a new row into the collection's table.
     *
     * Validates $input (full, not partial), generates a uuid, stamps actor + timestamps,
     * inserts the row, and returns the full stored row.
     *
     * @param array<string, mixed> $input  Field values supplied by the caller.
     * @return array<string, mixed>        The inserted row as read back from the database.
     * @throws \Glueful\Lemma\Collections\Exceptions\RowValidationException on validation failure.
     */
    public function create(CollectionDefinition $def, array $input, Actor $actor): array
    {
        $coerced = $this->validator->validate($def, $input, false);

        $now  = date('Y-m-d H:i:s');
        $uuid = PublicId::generate('row');

        $row = array_merge($coerced, [
            'uuid'            => $uuid,
            'created_at'      => $now,
            'updated_at'      => $now,
            'created_by_type' => $actor->type,
            'created_by_id'   => $actor->id,
            'updated_by_type' => $actor->type,
            'updated_by_id'   => $actor->id,
        ]);

        $this->connection->table($def->tableName)->insert($row);

        return $this->fetchOrFail($def, $uuid);
    }

    /**
     * Partially update an existing row.
     *
     * Only the fields present in $input are modified; absent fields retain their stored values.
     * Stamps updated_by_* and updated_at; never changes created_by_* columns.
     *
     * @param array<string, mixed> $input  Partial field values to apply.
     * @return array<string, mixed>        The updated row as read back from the database.
     * @throws RowNotFoundException        when no row with $uuid exists.
     * @throws \Glueful\Lemma\Collections\Exceptions\RowValidationException on validation failure.
     */
    public function update(CollectionDefinition $def, string $uuid, array $input, Actor $actor): array
    {
        // Confirm the row exists before validating.
        $this->fetchOrFail($def, $uuid);

        $coerced = $this->validator->validate($def, $input, true, $uuid);

        $changes = array_merge($coerced, [
            'updated_at'      => date('Y-m-d H:i:s'),
            'updated_by_type' => $actor->type,
            'updated_by_id'   => $actor->id,
        ]);

        $this->connection->table($def->tableName)
            ->where('uuid', $uuid)
            ->update($changes);

        return $this->fetchOrFail($def, $uuid);
    }

    /**
     * Delete a row from the collection's table.
     *
     * @throws RowNotFoundException when no row with $uuid exists.
     */
    public function delete(CollectionDefinition $def, string $uuid): void
    {
        $this->fetchOrFail($def, $uuid);

        $this->connection->table($def->tableName)
            ->where('uuid', $uuid)
            ->delete();
    }

    /**
     * Retrieve a row from the collection's table by UUID.
     *
     * @return array<string, mixed>
     * @throws RowNotFoundException when no row with $uuid exists.
     */
    public function find(CollectionDefinition $def, string $uuid): array
    {
        return $this->fetchOrFail($def, $uuid);
    }

    // ------------------------------------------------------------------

    /**
     * Fetch a row by UUID, throwing RowNotFoundException when absent.
     *
     * @return array<string, mixed>
     * @throws RowNotFoundException
     */
    private function fetchOrFail(CollectionDefinition $def, string $uuid): array
    {
        $row = $this->connection->table($def->tableName)
            ->where('uuid', $uuid)
            ->first();

        if ($row === null) {
            throw RowNotFoundException::forUuid($def->tableName, $uuid);
        }

        return $row;
    }
}
