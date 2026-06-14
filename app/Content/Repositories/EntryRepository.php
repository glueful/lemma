<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Support\OptimisticLockException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class EntryRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApplicationContext $context,
        private readonly ContentTypeRepository $types,
    ) {
    }

    /** Create entry identity + an empty draft for the locale. Returns entry uuid. */
    public function createEntry(string $contentTypeUuid, string $locale, int $schemaVersion, ?string $actor): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => $contentTypeUuid,
            'status' => 'active',
            'created_by' => $actor,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        $this->db->table('entry_drafts')->insert([
            'entry_uuid' => $uuid,
            'locale' => $locale,
            'fields' => json_encode([], JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'lock_version' => 0,
            'updated_by' => $actor,
            'updated_at' => $this->now(),
        ]);
        return $uuid;
    }

    /**
     * Save the draft working copy under optimistic concurrency. The caller passes the
     * lock_version it last read; if the row has moved on, throw (controller -> 409).
     *
     * The optimistic-lock CAS and the entry_references projection rebuild run inside one
     * transaction (V1_DESIGN §4): the CAS `update` runs first and a stale save (0 affected)
     * throws OptimisticLockException — which rolls the transaction back BEFORE any projection
     * write. The projection is rebuilt only after the CAS reports `affected >= 1`.
     *
     * @param array<string,mixed> $fields already-validated, cleaned payload
     */
    public function saveDraft(
        string $entryUuid,
        string $locale,
        array $fields,
        int $schemaVersion,
        int $expectedLockVersion,
        ?string $actor,
    ): void {
        db($this->context)->transaction(function () use (
            $entryUuid,
            $locale,
            $fields,
            $schemaVersion,
            $expectedLockVersion,
            $actor,
        ): void {
            $affected = $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entryUuid)
                ->where('locale', '=', $locale)
                ->where('lock_version', '=', $expectedLockVersion)
                ->update([
                    'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                    'schema_version' => $schemaVersion,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_by' => $actor,
                    'updated_at' => $this->now(),
                ]);
            if ($affected < 1) {
                // Stale save: throw inside the transaction so it rolls back before any
                // projection write. Controller maps OptimisticLockException -> 409.
                throw new OptimisticLockException();
            }

            $this->rebuildReferenceProjection($entryUuid, $fields);
        });
    }

    /**
     * Rebuild the entry_references projection for the saved draft. Resolves the content
     * type's schema so we know which fields are reference/asset. If the content type is
     * unresolvable (e.g. a synthetic uuid in a unit-style test), there are no schema fields
     * to project, so the projection is simply cleared for this entry.
     *
     * @param array<string,mixed> $fields
     */
    private function rebuildReferenceProjection(string $entryUuid, array $fields): void
    {
        $entry = $this->findEntry($entryUuid);
        $type = $entry === null
            ? null
            : $this->types->findByUuid((string) $entry['content_type_uuid']);
        $schema = $type === null
            ? ContentTypeSchema::fromArray([])
            : ContentTypeSchema::fromArray($type['schema']);

        (new ReferenceProjectionRepository($this->db))
            ->rebuildForEntry($entryUuid, $schema, $fields);
    }

    /** @return array<string,mixed>|null */
    public function findEntry(string $uuid): ?array
    {
        return $this->db->table('entries')->where('uuid', '=', $uuid)->first() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findDraft(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($row === null) {
            return null;
        }
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['lock_version'] = (int) $row['lock_version'];
        $row['schema_version'] = (int) $row['schema_version'];
        return $row;
    }

    public function softDelete(string $uuid): void
    {
        $this->db->table('entries')->where('uuid', '=', $uuid)
            ->update(['status' => 'deleted', 'updated_at' => $this->now()]);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
