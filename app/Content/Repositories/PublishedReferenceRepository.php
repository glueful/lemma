<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\SchemaProjector;
use Glueful\Database\Connection;

/**
 * The PUBLISHED-reference projection (term-archives/facets spec §1) — the single source
 * of "published source references published term". Rebuilt per (entry, locale) from the
 * PUBLISHED version's reference fields by ProjectPublishedReferencesListener; re-driven
 * by `lemma:resync`. Reference fields only (never asset), regardless of `filterable` —
 * flipping `filterable` later must not require a backfill; endpoints gate at read time.
 *
 * The pinned version's fields are projected FORWARD through SchemaProjector (its
 * schema_version → current) before scanning: a rollback re-pins an older version whose
 * reference fields may since have been renamed/deleted, and the projection must mirror
 * what delivery actually serves (DeliveryItemShaper projects the same way; the rollback
 * path in PublishService does the equivalent for the draft-side projection).
 *
 * Read queries (facetCounts / membershipPredicate) join the TARGET's publication at
 * read time — delete hygiene here is not the liveness mechanism (spec §1: a term can
 * be unpublished without being deleted).
 */
final class PublishedReferenceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly SchemaProjector $schemaProjector,
    ) {
    }

    /**
     * Rebuild the projection rows for one (entry, locale) from its PUBLISHED version:
     * clear that locale's rows, project the pinned fields forward to the current schema,
     * then re-insert. No publication (or no type) → the clear still ran, so stale rows
     * never survive. Idempotent.
     */
    public function projectFromPublished(string $entryUuid, string $typeUuid, string $locale): void
    {
        $this->clearForEntryLocale($entryUuid, $locale);

        $row = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['v.fields', 'v.schema_version'])
            ->where('p.entry_uuid', '=', $entryUuid)
            ->where('p.locale', '=', $locale)
            ->first();
        if ($row === null) {
            return;
        }
        $fields = json_decode((string) $row['fields'], true);
        if (!is_array($fields)) {
            return;
        }
        $typeRow = $this->types->findByUuid($typeUuid);
        if ($typeRow === null) {
            return;
        }
        // Rollback safety: re-pinned older versions carry older schema semantics —
        // apply the migration chain (renames/deletes) so field names match the
        // CURRENT schema the scan below (and the read endpoints) use.
        $fields = $this->schemaProjector->project($typeUuid, (int) ($row['schema_version'] ?? 0), $fields);
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);

        $seen = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'reference') {
                continue; // asset fields are never projected (spec §1)
            }
            foreach (ReferenceProjectionRepository::targets($fields[$f->name] ?? null) as $target) {
                $key = $f->name . '|' . $target;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $this->db->table('published_entry_references')->insert([
                    'source_entry_uuid' => $entryUuid,
                    'source_content_type_uuid' => $typeUuid,
                    'field' => $f->name,
                    'target_entry_uuid' => $target,
                    'locale' => $locale,
                ]);
            }
        }
    }

    public function clearForEntryLocale(string $entryUuid, string $locale): void
    {
        $this->db->table('published_entry_references')
            ->where('source_entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->delete();
    }

    /** Whole-entry delete: drop every locale's rows where the entry is the SOURCE. */
    public function clearForEntry(string $entryUuid): void
    {
        $this->db->table('published_entry_references')
            ->where('source_entry_uuid', '=', $entryUuid)
            ->delete();
    }

    /** Hygiene on term delete: drop rows pointing AT the entry (read-time joins stay the liveness rule). */
    public function clearForTarget(string $targetEntryUuid): void
    {
        $this->db->table('published_entry_references')
            ->where('target_entry_uuid', '=', $targetEntryUuid)
            ->delete();
    }
}
