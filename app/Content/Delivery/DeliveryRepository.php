<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * The leak-proof delivery read path.
 *
 * Every query reads from `entry_publications` (the spine — only published entries
 * have a row there) joined to the pinned `entry_versions`. There is no status
 * column on the read path, so drafts/unpublished entries physically cannot be
 * returned. The repository never touches `entry_drafts`.
 */
final class DeliveryRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string,mixed>|null $filter  compiled [sql, bindings] from FilterCompiler (Task 4)
     * @param array<string,mixed>|null $order   compiled ORDER BY from SortCompiler (Task 5)
     * @param array<string,mixed>|null $cursor  decoded cursor (Task 6)
     * @return list<array<string,mixed>> newest-published first (default order)
     */
    public function listPublished(
        string $contentTypeUuid,
        string $locale,
        int $limit = 20,
        ?array $filter = null,
        ?array $order = null,
        ?array $cursor = null,
    ): array {
        $q = $this->base($contentTypeUuid, $locale);
        // filter/order/cursor applied by Tasks 4-6 via whereRaw/orderByRaw; default order:
        $q->orderBy('p.published_at', 'DESC')->orderBy('v.id', 'DESC');
        $q->limit($limit);
        return array_map([$this, 'hydrate'], $q->get());
    }

    /** @return array<string,mixed>|null */
    public function findPublishedByRoute(string $contentTypeUuid, string $locale, string $slug): ?array
    {
        $row = $this->base($contentTypeUuid, $locale)
            ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
            ->where('r.content_type_uuid', '=', $contentTypeUuid)
            ->where('r.locale', '=', $locale)
            ->where('r.slug', '=', $slug)
            ->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function findPublishedByUuid(string $contentTypeUuid, string $locale, string $entryUuid): ?array
    {
        $row = $this->base($contentTypeUuid, $locale)->where('p.entry_uuid', '=', $entryUuid)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Batch-load the published versions for a set of entry uuids (any type/locale) —
     * used by ReferenceResolver (Task 6). One query.
     *
     * @param list<string> $entryUuids
     * @return array<string,array<string,mixed>> keyed by entry_uuid
     */
    public function publishedByEntryUuids(array $entryUuids, string $locale): array
    {
        if ($entryUuids === []) {
            return [];
        }
        $rows = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['p.entry_uuid', 'v.uuid AS version_uuid', 'v.fields', 'v.version'])
            ->whereIn('p.entry_uuid', $entryUuids)
            ->where('p.locale', '=', $locale)
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['entry_uuid']] = $this->hydrate($r);
        }
        return $out;
    }

    private function base(string $contentTypeUuid, string $locale): QueryBuilder
    {
        // entry_publications is the spine; join the pinned version. We also join
        // entries for the content-type filter and to never serve archived/deleted.
        return $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select([
                'p.entry_uuid',
                'p.locale',
                'p.version_uuid',
                'p.published_at',
                'v.fields',
                'v.version',
                'v.schema_version',
                'v.id',
            ])
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')   // never serve archived/deleted entries
            ->where('p.locale', '=', $locale);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['version'] = (int) $row['version'];
        return $row;
    }
}
