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
     * Cursor/keyset list — the default, publish-churn-stable read path.
     *
     * Order comes from {@see SortCompiler} (default `published_at DESC, v.id DESC`); when
     * a decoded `$cursor` is present a keyset predicate restricts the result to rows
     * *after* the cursor's position, so paging never skips or duplicates rows even as
     * the published set changes underneath it. This is deliberately NOT the framework's
     * offset `paginate()` (unstable + O(offset)); the offset convenience path is
     * {@see paginatePublished()}.
     *
     * @param array{sql:string,bindings:list<mixed>}|null $filter compiled from FilterCompiler
     * @param array{sql:string,expr:string,direction:string,field:?string,column:?string}|null $order
     *        compiled from SortCompiler; null => default newest-first order
     * @param array{sort:scalar|null,id:int}|null $cursor decoded keyset position (Cursor::decode)
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
        $this->applyFilter($q, $filter);

        $order ??= SortCompiler::defaultOrder();

        // Keyset predicate: the ORDER BY mixes the primary direction with a fixed
        // `v.id DESC` tiebreaker, so a single row-value comparison cannot express it.
        // Use the expanded equivalent: `expr <dirOp> ? OR (expr = ? AND v.id < ?)`,
        // with all values BOUND. dirOp is `<` for DESC, `>` for ASC.
        if ($cursor !== null) {
            $dirOp = $order['direction'] === 'ASC' ? '>' : '<';
            $expr = $order['expr'];
            $q->whereRaw(
                "({$expr} {$dirOp} ? OR ({$expr} = ? AND v.id < ?))",
                [$cursor['sort'], $cursor['sort'], $cursor['id']]
            );
        }

        $q->orderByRaw(substr($order['sql'], strlen('ORDER BY ')));
        $q->limit($limit);
        return array_map([$this, 'hydrate'], $q->get());
    }

    /**
     * Offset convenience path — `page`/`perPage`. Uses the framework's offset-based
     * `QueryBuilder::paginate()` (NOT hand-rolled) so it matches the admin API's
     * pagination envelope. Offset paging is not churn-stable; that is an accepted
     * trade-off for this opt-in convenience mode (use the cursor path for live feeds).
     *
     * @param array{sql:string,bindings:list<mixed>}|null $filter compiled from FilterCompiler
     * @param array{sql:string,expr:string,direction:string,field:?string,column:?string}|null $order
     * @return array{data:list<array<string,mixed>>,total:int,current_page:int,per_page:int}
     */
    public function paginatePublished(
        string $contentTypeUuid,
        string $locale,
        int $page,
        int $perPage,
        ?array $filter = null,
        ?array $order = null,
    ): array {
        $q = $this->base($contentTypeUuid, $locale);
        $this->applyFilter($q, $filter);

        $order ??= SortCompiler::defaultOrder();
        $q->orderByRaw(substr($order['sql'], strlen('ORDER BY ')));

        $result = $q->paginate($page, $perPage);

        /** @var list<array<string,mixed>> $data */
        $data = $result['data'] ?? [];
        return [
            'data' => array_map([$this, 'hydrate'], $data),
            'total' => (int) ($result['total'] ?? 0),
            'current_page' => (int) ($result['current_page'] ?? $page),
            'per_page' => (int) ($result['per_page'] ?? $perPage),
        ];
    }

    /**
     * Build the keyset cursor position for a row under a given order, so the caller
     * (controller) can emit the next-page cursor. Reads the sort value from the same
     * place the keyset predicate compares against: the JSONB field for a field sort,
     * or the `published_at` column for the default order. `id` is the monotonic
     * `v.id` tiebreaker.
     *
     * @param array<string,mixed> $row a hydrated row from listPublished()
     * @param array{sql:string,expr:string,direction:string,field:?string,column:?string} $order
     * @return array{sort:scalar|null,id:int}
     */
    public function cursorFor(array $row, array $order): array
    {
        if ($order['field'] !== null) {
            /** @var array<string,mixed> $fields */
            $fields = $row['fields'] ?? [];
            $sort = $fields[$order['field']] ?? null;
        } else {
            $sort = $row[$order['column'] ?? 'published_at'] ?? null;
        }

        return [
            'sort' => is_scalar($sort) ? $sort : null,
            'id' => (int) ($row['id'] ?? 0),
        ];
    }

    /**
     * @param array{sql:string,bindings:list<mixed>}|null $filter
     */
    private function applyFilter(QueryBuilder $q, ?array $filter): void
    {
        // Compiled filter from FilterCompiler (Task 4): a typed JSONB predicate over
        // entry_versions.fields, bound placeholders only. The predicate uses the same
        // expression as the filterable-field expression index so it hits the index.
        if ($filter !== null && ($filter['sql'] ?? '') !== '') {
            /** @var list<mixed> $bindings */
            $bindings = $filter['bindings'] ?? [];
            $q->whereRaw((string) $filter['sql'], $bindings);
        }
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
     * Joins `entries` and filters `e.status = 'active'` so an archived-but-published
     * target is treated as gone (resolves to null), consistent with the main read
     * path's {@see base()} guard — a referenced entry that's been archived must not
     * surface through another entry's reference.
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
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select(['p.entry_uuid', 'v.uuid AS version_uuid', 'v.fields', 'v.version'])
            ->whereIn('p.entry_uuid', $entryUuids)
            ->where('e.status', '=', 'active')
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
