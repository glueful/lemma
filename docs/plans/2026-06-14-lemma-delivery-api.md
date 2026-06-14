# Lemma Delivery API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A public, read-only delivery API (`/v1/content/...`) that serves **only published** content by reading exclusively through `entry_publications ⋈ entry_versions`, with field selection/expansion, filtering/sorting on declared filterable fields (backed by Postgres expression indexes), API-key `read:content` scopes, rate limiting, `ETag`/cache-tag caching, and reference resolution.

**Architecture:** A dedicated `DeliveryRepository` whose every query joins `entry_publications` to `entry_versions` — there is **no status column to filter on**, so drafts physically cannot leak (V1_DESIGN §2). Filtering/sorting is restricted to fields the content type marks `filterable` (with a declared `filter_type`), each backed by a Postgres expression index created out-of-band by a queued job (`CREATE INDEX CONCURRENTLY`). References stored as entry UUIDs in `fields` JSONB are resolved at read time to the target's **published** version via batch loading. Responses carry `ETag` (hash of version uuid + selection) and cache-tag headers; the framework's field-selection seam (`FieldSelector`/`#[Fields]`) shapes output.

**Tech Stack:** PHP 8.3, Glueful framework ^1.56.0, PostgreSQL (JSONB + expression indexes), the framework field-selection seam (`Glueful\Support\FieldSelection\*`, `#[Fields]`, `FieldSelectionMiddleware`), API keys + a Lemma `require_content_scope` middleware (the core `#[RequireScope]`/`RequireScopeMiddleware` seam is attribute-only and fail-open for fluent route-file routes — see Task 7), `CacheStore` tag invalidation, the queue (for `CREATE INDEX CONCURRENTLY`). Builds on the committed foundation (`docs/plans/2026-06-13-lemma-foundation.md`).

**Source of truth:** [`../V1_DESIGN.md`](../V1_DESIGN.md) §1 (filterable fields + expression indexes), §4 (references), §6 (delivery API). Where this says "per §N", that section is authoritative.

**Scope boundary (this plan only):** the read path + filtering + field selection + scopes + rate limit + caching headers + reference resolution + the expression-index lifecycle + the reference-projection maintenance on draft save. **Deferred to other plans:** publish *pipeline* side-effects that *invalidate* the cache tags / purge CDN / reindex search (that is the **Publishing Pipeline** plan — this plan only *emits* the tags/ETag and exposes the read path; cache population is per-request); preview tokens; the admin SPA; export.

---

## Conventions (inherited from the foundation — read once)

These are already established and verified in the foundation; reuse them verbatim:
- **Repositories** inject `Glueful\Database\Connection`; query via `$this->db->table(...)->where('k','=',$v)->...`. Richer JSONB predicates go through `whereRaw('<expr> <op> ?', [$binding])` (the builder exposes `whereJsonContains` but not typed `->>` casts — confirm `whereRaw` signature/binding order against `src/Database/QueryBuilder.php` at first use).
- **IDs** are 12-char nanoids (`Glueful\Helpers\Utils::generateNanoID(12)`), stored `string('uuid',12)`.
- **`json()` columns are JSONB** on Postgres.
- **Migrations** in `database/migrations/` (plain unnamespaced classes; `up()` opens with `if ($schema->hasTable('<t>')) { return; }`). Continue the numeric prefix after `008` → `009`, `010`, ...
- **Tests:** integration tests extend `App\Tests\Support\LemmaTestCase` (boots against `lemma_test`, clears the 7 Lemma tables per test); run via `composer test` / `composer test:phpunit -- --filter X`. Unit tests extend plain `PHPUnit\Framework\TestCase`. **PSR-12** (`composer phpcs` is a gate now — keep new code clean).
- **Responses** use `Glueful\Http\Response` statics.
- **Delivery is read-only** — it must never write; the only writes in this plan are (a) the reference-projection rows inside the *existing* draft-save transaction, and (b) the expression-index DDL via the queued job.

---

## File structure

```
config/
  lemma.php                              # MODIFY: + delivery defaults (cache_ttl, per_page caps)
app/Content/
  Delivery/
    DeliveryRepository.php               # reads ONLY publications⋈versions (list + single); filter/sort/cursor
    FilterCompiler.php                   # ?filter[field][op]=v -> safe typed JSONB predicate (filterable fields only)
    SortCompiler.php                     # ?sort=field:dir -> ORDER BY on a filterable field's index expression
    Cursor.php                           # opaque cursor encode/decode (stable under publish churn)
    ReferenceResolver.php                # batch-resolves entry-UUID references -> target published versions
  Indexing/
    FilterIndexPlanner.php               # derives the desired expression indexes from a content type's schema
    EnsureFilterIndexesJob.php           # queued: CREATE INDEX CONCURRENTLY per filterable field (idempotent)
  Repositories/
    ReferenceProjectionRepository.php    # write entry_references for an entry's draft; reverse lookups
    EntryRepository.php                  # MODIFY: rebuild the reference projection inside saveDraft's write
  Services/
    PublishService.php                   # MODIFY: snapshot reference projection through publish (optional; see Task)
  Http/
    Controllers/DeliveryController.php   # GET /v1/content/{type}, GET /v1/content/{type}/{slugOrUuid}
    RequireContentScope.php               # fail-closed scope middleware (alias require_content_scope)
    DeliveryEtag.php                      # ETag + Cache-Control + cache-tag header helper
routes/
  lemma_content.php                      # NEW route file (auto-discovered): /v1/content group
database/migrations/
  009_AddFilterIndexRegistry.php         # tracks which expression indexes exist (name, type_uuid, field, status)
tests/
  Unit/Content/FilterCompilerTest.php
  Unit/Content/CursorTest.php
  Integration/Content/DeliveryRepositoryTest.php
  Integration/Content/ReferenceProjectionTest.php
  Integration/Content/ReferenceResolverTest.php
  Integration/Indexing/EnsureFilterIndexesJobTest.php
  Integration/Http/DeliveryApiTest.php
  Integration/DeliveryFlowTest.php       # publish -> read back via /v1/content (kernel-level)
```

---

### Task 1: Delivery config + the published-read repository (the leak-proof core)

**Files:** Create `app/Content/Delivery/DeliveryRepository.php`; Modify `config/lemma.php`; Test `tests/Integration/Content/DeliveryRepositoryTest.php`.

The repository reads **only** `entry_publications JOIN entry_versions` — prove no draft/unpublished row is ever returned.

- [ ] **Step 1: Write the failing test** — seed (via the foundation repos + `PublishService`) a published entry and an unpublished one; assert `findPublished($typeUuid, locale, slugOrUuid)` returns the published one and `null` for the unpublished, and `listPublished` excludes the unpublished.

```php
<?php
declare(strict_types=1);
namespace App\Tests\Integration\Content;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;

final class DeliveryRepositoryTest extends LemmaTestCase
{
    private string $type;
    private function publish(string $title, string $slug): string
    {
        $entries = new EntryRepository($this->connection());
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        (new PublishService($this->appContext(), $entries, new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()), new FieldValidator()))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }
    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }
    private function repo(): DeliveryRepository { return new DeliveryRepository($this->connection()); }

    public function testListReturnsOnlyPublished(): void
    {
        $this->publish('Live', 'live');
        // an unpublished entry (draft saved, never published)
        (new EntryRepository($this->connection()))->createEntry($this->type, 'en', 1, 'user00000001');
        $rows = $this->repo()->listPublished($this->type, 'en', limit: 20);
        self::assertCount(1, $rows);
        self::assertSame('Live', $rows[0]['fields']['title']);
    }

    public function testFindBySlugReturnsPublishedVersion(): void
    {
        $this->publish('Hello', 'hello');
        $row = $this->repo()->findPublishedByRoute($this->type, 'en', 'hello');
        self::assertSame('Hello', $row['fields']['title']);
        self::assertArrayHasKey('version_uuid', $row);
        self::assertNull($this->repo()->findPublishedByRoute($this->type, 'en', 'does-not-exist'));
    }
}
```

- [ ] **Step 2: Run → fail** (`composer test:phpunit -- --filter DeliveryRepositoryTest`).
- [ ] **Step 3: Implement `DeliveryRepository`.** Every method joins publications→versions; returns the version's hydrated `fields` + identity. `findPublishedByRoute` joins `entry_routes` too (the denormalized `content_type_uuid` means the route lookup needs no `entries` join). Single-by-uuid resolves the entry's *current publication*.

```php
<?php
declare(strict_types=1);
namespace App\Content\Delivery;

use Glueful\Database\Connection;

final class DeliveryRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> newest-published first (default order) */
    public function listPublished(
        string $contentTypeUuid,
        string $locale,
        int $limit = 20,
        ?array $filter = null,   // compiled [sqlExpr, bindings] from FilterCompiler (Task 4)
        ?array $order = null,    // compiled ORDER BY from SortCompiler (Task 5)
        ?array $cursor = null,   // decoded cursor (Task 6)
    ): array {
        $q = $this->base($contentTypeUuid, $locale);
        // filter/order/cursor applied by Tasks 4–6 via whereRaw/orderByRaw; default order:
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
     * used by ReferenceResolver (Task 7). One query.
     * @param list<string> $entryUuids
     * @return array<string,array<string,mixed>> keyed by entry_uuid
     */
    public function publishedByEntryUuids(array $entryUuids, string $locale): array
    {
        if ($entryUuids === []) { return []; }
        $rows = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['p.entry_uuid', 'v.uuid as version_uuid', 'v.fields', 'v.version'])
            ->whereIn('p.entry_uuid', $entryUuids)
            ->where('p.locale', '=', $locale)
            ->get();
        $out = [];
        foreach ($rows as $r) { $out[$r['entry_uuid']] = $this->hydrate($r); }
        return $out;
    }

    private function base(string $contentTypeUuid, string $locale): \Glueful\Database\QueryBuilder
    {
        // entry_publications is the spine; join the pinned version. We also need the
        // entry's content_type — join entries ONLY for the type filter on list.
        return $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select([
                'p.entry_uuid', 'p.locale', 'p.version_uuid', 'p.published_at',
                'v.fields', 'v.version', 'v.schema_version', 'v.id',
            ])
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')   // never serve archived/deleted entries
            ->where('p.locale', '=', $locale);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): array
    {
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['version'] = (int) $row['version'];
        return $row;
    }
}
```

> **Verify against `src/Database/QueryBuilder.php`:** the `join('t as alias', a, op, b)` form, `select()` with `as` aliases, `orderBy`, `whereIn`. If the builder doesn't support table aliases in `join`/`table`, fall back to fully-qualified names without aliases (the queries are still simple). The leak-proof property (no status column on the read path) does not depend on aliasing.

- [ ] **Step 4: Run → pass.** Add `config/lemma.php` keys: `delivery.default_per_page` (20), `delivery.max_per_page` (100), `delivery.cache_ttl` (per-type override later). (No `public_types` in v1 — delivery is always API-key gated; see Task 7's route note for why anonymous-per-type is deferred.)
- [ ] **Step 5: Commit** `Add leak-proof DeliveryRepository (publications⋈versions read path)`.

---

### Task 2: The reference projection on draft save (V1_DESIGN §4 write-time projection)

**Files:** Create `app/Content/Repositories/ReferenceProjectionRepository.php`; Modify `app/Content/Repositories/EntryRepository.php` (rebuild the projection inside `saveDraft`'s write); Test `tests/Integration/Content/ReferenceProjectionTest.php`.

§4: `entry_references` is a write-time projection, **rebuilt on every draft save inside the same transaction**. A reference field stores target **entry UUIDs** in `fields`. The projection extracts `(source_entry_uuid, source_field, target_entry_uuid)` rows from the draft's reference/asset fields, given the content type schema (so we know which fields are `reference`/`asset`).

- [ ] **Step 1: Write the failing test** — create a type with a `reference` field `author` (and a plain `title`), save a draft whose `author` points at another entry uuid, assert `entry_references` has the row; re-save with a different target, assert the projection is *replaced* (not duplicated); assert reverse lookup `referencesTo(target)` finds the source.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement `ReferenceProjectionRepository`** with `rebuildForEntry(string $sourceEntryUuid, ContentTypeSchema $schema, array $fields): void` (delete-then-insert the source's rows for the reference/asset fields it actually has) and `referencesTo(string $targetEntryUuid): array`. Then **modify `EntryRepository::saveDraft`** to call it *inside the same write* — wrap the existing draft `update` + the projection rebuild in `db($context)->transaction(...)` (this requires `EntryRepository` to gain an injected `ApplicationContext` + `ContentTypeRepository` to resolve the schema, OR pass the schema in from the controller). **Decision:** inject `ApplicationContext` + `ContentTypeRepository` into `EntryRepository` (autowired) and resolve the schema by the entry's content type inside `saveDraft`. Update the foundation's `EntryApiTest`/`EntryRepositoryTest` constructors accordingly.

```php
<?php
declare(strict_types=1);
namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ReferenceProjectionRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $fields the cleaned draft fields */
    public function rebuildForEntry(string $sourceEntryUuid, ContentTypeSchema $schema, array $fields): void
    {
        $this->db->table('entry_references')->where('source_entry_uuid', '=', $sourceEntryUuid)->delete();
        $rows = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'reference' && $f->type !== 'asset') { continue; }
            foreach ($this->targets($fields[$f->name] ?? null) as $target) {
                $rows[] = [
                    'id' => null, // surrogate autoincrement; omit if the builder requires it absent
                    'source_entry_uuid' => $sourceEntryUuid,
                    'source_field' => $f->name,
                    'target_entry_uuid' => $target,
                ];
            }
        }
        // dedupe on the unique (source,field,target) and insert
        foreach ($this->dedupe($rows) as $r) {
            unset($r['id']);
            $this->db->table('entry_references')->insert($r);
        }
    }

    /** @return list<string> reverse: entry uuids that reference the target */
    public function referencesTo(string $targetEntryUuid): array
    {
        return array_values(array_unique(array_column(
            $this->db->table('entry_references')->select(['source_entry_uuid'])
                ->where('target_entry_uuid', '=', $targetEntryUuid)->get(),
            'source_entry_uuid'
        )));
    }

    /** A reference field value is a uuid string or a list of uuid strings. @return list<string> */
    private function targets(mixed $value): array
    {
        if (is_string($value) && $value !== '') { return [$value]; }
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn($v) => is_string($v) ? $v : '', $value
            ), static fn($v) => $v !== ''));
        }
        return [];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function dedupe(array $rows): array
    {
        $seen = []; $out = [];
        foreach ($rows as $r) {
            $k = $r['source_field'] . '|' . $r['target_entry_uuid'];
            if (!isset($seen[$k])) { $seen[$k] = true; $out[] = $r; }
        }
        return $out;
    }
}
```

> **Note on the `EntryRepository` change:** this is the one foundation file this plan modifies. Keep `saveDraft`'s optimistic-lock CAS semantics intact — the projection rebuild happens only *after* the CAS `update` reports `affected >= 1`, all inside one `db($context)->transaction()`. The reviewer for that task must confirm the 409 path still works (a stale save throws before any projection write).

- [ ] **Step 4: Run → pass** (incl. re-save replaces, reverse lookup). Re-run the foundation's `EntryRepositoryTest`/`EntryApiTest` after the constructor change → still green.
- [ ] **Step 5: Commit** `Project entry_references on draft save (V1_DESIGN §4)`.

---

### Task 3: Filterable-field expression-index lifecycle (queued `CREATE INDEX CONCURRENTLY`)

**Files:** Create `database/migrations/009_AddFilterIndexRegistry.php`, `app/Content/Indexing/FilterIndexPlanner.php`, `app/Content/Indexing/EnsureFilterIndexesJob.php`; Test `tests/Integration/Indexing/EnsureFilterIndexesJobTest.php`.

§1: a filterable field declares a `filter_type` (`string|number|boolean|datetime|enum`), which fixes the index expression cast (`(fields ->> 'price')::numeric`). Lemma creates the expression index **by a queued job** (`CREATE INDEX CONCURRENTLY`), never inline in a request. A registry table tracks which indexes exist.

- [ ] **Step 1: Write the failing test** — enqueue/run `EnsureFilterIndexesJob` for a content type with a filterable `price` (number) field; assert a Postgres index on `((fields->>'price')::numeric)` exists on `entry_versions` and a registry row is recorded; re-running is a no-op (idempotent); a removed filterable field drops its index. (Use `pg_indexes`/`information_schema` to assert.)
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.**
  - `009` migration: `lemma_filter_indexes(uuid, content_type_uuid, field, filter_type, index_name, status, created_at)` with unique `(content_type_uuid, field)`.
  - `FilterIndexPlanner::desiredIndexes(ContentTypeSchema): list<{field, filter_type, index_name, expression}>` — `index_name = 'lemma_fidx_' . substr(sha1($typeUuid.$field),0,16)`; `expression` from `filter_type`:
    - `number` → `((fields ->> 'field')::numeric)`
    - `datetime` → `((fields ->> 'field')::timestamptz)`
    - `boolean` → `((fields ->> 'field')::boolean)`
    - `string`/`enum` → `((fields ->> 'field'))` (text)
  - `EnsureFilterIndexesJob` (a `Glueful\Queue\Job` subclass; confirm the base/`handle()` signature against `src/Queue/`): diff desired vs registry; for each missing, run **raw** `CREATE INDEX CONCURRENTLY IF NOT EXISTS <name> ON entry_versions (<expression>)` and for each removed, `DROP INDEX CONCURRENTLY IF EXISTS <name>`; update the registry. **`CREATE INDEX CONCURRENTLY` cannot run inside a transaction** — execute it on a non-transactional connection/PDO (`$connection->getPDO()->exec(...)`; verify the raw-exec accessor). The field name is validated `[a-z][a-z0-9_]*` by `FieldDefinition` already, so it's safe to interpolate into the index expression — **assert that invariant in the job before building the SQL** (defense in depth; never interpolate an unvalidated field name).
  - Wire the job: enqueue it from `ContentTypeController::store`/`updateSchema` (the admin model-change path) via the framework `QueueManager` — `app($context, QueueManager::class)->push(EnsureFilterIndexesJob::class, ['content_type_uuid' => $uuid])`. (Modifies the foundation's `ContentTypeController` — small, additive.)
- [ ] **Step 4: Run → pass** (create/idempotent/drop). Note the managed-Postgres caveat from V1_DESIGN open questions: `CREATE INDEX CONCURRENTLY` may be restricted on some hosts — the job logs and degrades (the field is simply not filterable until the index exists; Task 4 enforces "unindexed ⇒ not filterable").
- [ ] **Step 5: Commit** `Add filterable-field expression-index lifecycle (queued CREATE INDEX CONCURRENTLY)`.

---

### Task 4: `FilterCompiler` — safe typed JSONB predicates (filterable fields only)

**Files:** Create `app/Content/Delivery/FilterCompiler.php`; Test `tests/Unit/Content/FilterCompilerTest.php`.

`?filter[price][gt]=10&filter[status][in]=a,b` → a compiled `[whereRawExpr, bindings]` applied to `entry_versions` (`v.`). **Only fields the schema marks `filterable` are accepted; everything else is rejected (a product rule, §1).** The `filter_type` fixes the cast and the allowed operators (`gt/gte/lt/lte` for number/datetime; `eq/neq/in` for string/enum/boolean).

- [ ] **Step 1: Write the failing test** (pure unit, no DB): given a `ContentTypeSchema` with filterable `price`(number) + `status`(enum) and a non-filterable `title`, assert: a `price[gt]=10` compiles to `(v.fields ->> 'price')::numeric > ?` with binding `[10]`; `status[in]=a,b` compiles to `(v.fields ->> 'status') IN (?, ?)`; filtering on `title` throws `UnfilterableFieldException`; an operator not allowed for the type (e.g. `status[gt]`) throws; a malformed value throws.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement `FilterCompiler::compile(ContentTypeSchema $schema, array $filterParam): array` returning `['sql' => string, 'bindings' => list<mixed>]`** (AND-joined). Cast/operator table mirrors Task 3's expression so the index is actually used. **Never interpolate the value — always a `?` placeholder binding.** The field name comes from the schema (already validated), so interpolating it into `->> 'field'` is safe; still assert the `[a-z][a-z0-9_]*` shape before building the string.
- [ ] **Step 4: Run → pass.** Then wire it into `DeliveryRepository::listPublished` via `whereRaw($sql, $bindings)`.
- [ ] **Step 5: Commit** `Add FilterCompiler (typed, filterable-only JSONB predicates)`.

---

### Task 5: `SortCompiler` + cursor pagination

**Files:** Create `app/Content/Delivery/SortCompiler.php`, `app/Content/Delivery/Cursor.php`; Tests `tests/Unit/Content/CursorTest.php` (+ sort assertions folded into `DeliveryRepositoryTest`).

- [ ] **Step 1: Write failing tests** — `SortCompiler` accepts `?sort=price:asc` only for filterable fields (else throws), producing `ORDER BY (v.fields ->> 'price')::numeric ASC, v.id DESC` (the `v.id` tiebreaker makes paging deterministic). `Cursor::encode/decode` round-trips an opaque base64 of `(last sort key + v.id)`; a tampered cursor decodes to null (ignored). Cursor paging is stable under publish churn because it keys on `v.id` (monotonic), not offset.
- [ ] **Step 2–4:** implement, wire into `listPublished` (when a `cursor` is present, add `WHERE (sortexpr, v.id) < (?, ?)` keyset predicate), pass. Keep `page`/`perPage` offset paging also supported (V1_DESIGN §6 "page/perPage also supported for convenience") — a separate simpler path.
- [ ] **Step 5: Commit** `Add sort + cursor pagination for delivery`.

---

### Task 6: `ReferenceResolver` — resolve references to published versions at read time

**Files:** Create `app/Content/Delivery/ReferenceResolver.php`; Test `tests/Integration/Content/ReferenceResolverTest.php`.

§4: references point at *entries*; the delivery API resolves them to the target's **published** version at read time. An unpublished target resolves to null/omitted (never a draft). Expansion uses **batch loading** (one query per referenced set), not per-entry fetches.

- [ ] **Step 1: Write failing test** — two entries A(published, references B) and B(published); resolving A's `author` reference returns B's published fields; if B is unpublished, A's `author` resolves to `null`; a circular A↔A is bounded by a depth limit (default 2) — no infinite loop.
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement `ReferenceResolver::expand(array $rootRows, ContentTypeSchema $schema, FieldSelector $selector, int $depth): array`** — collect all reference/asset uuids across `$rootRows` for the *requested* reference fields (per the field selector), `DeliveryRepository::publishedByEntryUuids(...)` them in one batch, splice resolved fields back in; recurse up to `depth` (the framework field-selection depth limit also bounds this). Unresolved → omit/null.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** `Add reference resolution (batch-loaded, published-only) for delivery`.

---

### Task 7: `DeliveryController` + routes + field selection + scopes + rate limit + ETag

**Files:** Create `app/Content/Http/Controllers/DeliveryController.php`, `app/Content/Http/DeliveryEtag.php`, `routes/lemma_content.php`; Test `tests/Integration/Http/DeliveryApiTest.php`.

- [ ] **Step 1: Write failing test** (controller-level, like the admin API tests): seed a published entry; `index`/`show` return 200 with the published fields; field selection (`?fields=title`) trims output; filtering by a filterable field works and a non-filterable filter 422s; `ETag` header present and a matching `If-None-Match` yields 304. (Auth/scope is exercised at the kernel level in Task 9; here drive the controller directly.)
- [ ] **Step 2: Run → fail.**
- [ ] **Step 3: Implement.**
  - Controller `index(Request, string $type)` and `show(Request, string $type, string $slugOrUuid)`: resolve the content type by slug (404 if unknown); build `DeliveryRepository` query with `FilterCompiler`/`SortCompiler`/`Cursor`; resolve references via `ReferenceResolver` for requested expansions; shape via the framework `FieldSelector`/`Projector` (inject `FieldSelector`; the `#[Fields(allowed: [...])]` whitelist is per-type — derive `allowed` from the schema's fields + reference paths); emit `ETag`/`Cache-Control`/cache-tag headers via `DeliveryEtag`.
  - `DeliveryEtag`: `etag = '"' . sha1($versionUuid . '|' . $selectionKey) . '"'`; `Cache-Control: public, max-age=<type ttl>`; `Cache-Tag: lemma:entry:{uuid}, lemma:type:{slug}` (the **Publishing Pipeline** plan invalidates these; this plan only sets them). 304 when `If-None-Match` matches.
  - **A Lemma `RequireContentScope` middleware (alias `require_content_scope`) — the core `RequireScopeMiddleware` is fail-open for fluent routes.** Verified: `RequireScopeMiddleware::handle()` reads `$route->getRequireScopeConfig()` (populated **only** by the `#[RequireScope]` attribute via `AttributeRouteLoader`) and **ignores its `...$params`** — so `->middleware('require_scope:read:content')` on a *route-file* route finds empty config and falls through to `$next` (no scope enforced). Since Lemma defines routes in files (not attributes), write a param-reading, fail-closed middleware mirroring core's logic:
    ```php
    final class RequireContentScope implements RouteMiddleware
    {
        public function handle(Request $request, callable $next, mixed ...$params): mixed
        {
            $required = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';
            if ($required === '') {
                return Response::forbidden('Scope required');
            }
            // api-key auth sets 'api_key_scopes'; its absence => not a scoped-key request => deny.
            if (!$request->attributes->has('api_key_scopes')) {
                return Response::forbidden('This route requires a scoped API key');
            }
            $granted = array_values(array_filter((array) $request->attributes->get('api_key_scopes', []), 'is_string'));
            if (!\Glueful\Auth\ApiKey\ApiKeyService::scopeSatisfies($granted, $required)) {
                return Response::forbidden('Insufficient scope: ' . $required);
            }
            return $next($request);
        }
    }
    ```
    > Verify `ApiKeyService::scopeSatisfies(array $granted, string $required): bool` and the `api_key_scopes` request attribute against `src/Routing/Middleware/RequireScopeMiddleware.php` / `src/Auth/ApiKey/ApiKeyService.php` — both confirmed present. Register the alias in `LemmaServiceProvider::services()`: `RequireContentScope::class => ['class' => …, 'shared' => true, 'autowire' => true, 'alias' => ['require_content_scope']]`.
  - `routes/lemma_content.php` (NEW file — auto-discovered by `RouteManifest`; do **not** call `loadRoutesFrom` in the provider, per the foundation's Task 14 finding):
    ```php
    $router->group(['prefix' => '/v1/content', 'middleware' => ['auth']], function (Router $router): void {
        $router->get('/{type}', [DeliveryController::class, 'index'])
            ->middleware('require_content_scope:read:content')->middleware('rate_limit')->rateLimit(120, 1, by: 'user');
        $router->get('/{type}/{slugOrUuid}', [DeliveryController::class, 'show'])
            ->middleware('require_content_scope:read:content')->middleware('rate_limit')->rateLimit(120, 1, by: 'user');
    });
    ```
    > **v1 delivery is always API-key gated** (`auth` + `require_content_scope:read:content`). The public/anonymous-per-type opt-in (V1_DESIGN §6) is **deferred to a post-v1 iteration**: it requires removing the group-level `auth` and a conditional-access middleware that allows anonymous reads only for types in an allow-list while still enforcing the scope for everything else — a clean follow-on, but a real design surface not worth opening in v1. Per-type scopes (`read:content:{type}`) are likewise a later refinement on the same middleware.
  - **Step 3b: Add a kernel-level scope test** — a `GET /v1/content/post` with **no** `api_key_scopes` (e.g. a JWT or no auth) must be **403**, and with a key carrying `read:content` must pass. This pins that the gate is fail-*closed* (guards against regressing to the core middleware's fluent-param no-op).
  - Register the new classes in `LemmaServiceProvider::services()` (autowire). `FieldSelectionMiddleware` may already process `?fields` globally — confirm whether to rely on it or invoke the `Projector` in-controller; the admin API didn't use it, delivery does.
- [ ] **Step 4: Run → pass.**
- [ ] **Step 5: Commit** `Add delivery controller + content routes (field selection, scopes, rate limit, ETag)`.

---

### Task 8: Register wiring + end-to-end delivery flow test

**Files:** Modify `app/Providers/LemmaServiceProvider.php` (register the delivery/indexing/reference services); Test `tests/Integration/DeliveryFlowTest.php`.

- [ ] **Step 1: Write failing kernel-level test** — as an authenticated API key holder with `read:content`: create a type (admin) → create+publish an entry (admin) → `GET /v1/content/post/{slug}` returns 200 with the published fields; an unpublished entry is absent from `GET /v1/content/post`; a request without the scope is 403; an `If-None-Match` with the right ETag is 304. (Reuse/extend the harness kernel helpers from the foundation's `FoundationFlowTest`; mint an API key with the scope — verify the `api_keys` minting path against `src/Auth/ApiKey/`.)
- [ ] **Step 2: Implement** the provider registrations; ensure `routes/lemma_content.php` is live (`route:debug`).
- [ ] **Step 3: Run the FULL suite** `composer test` (green) + `composer phpcs` (clean).
- [ ] **Step 4: Commit** `Wire delivery API; end-to-end delivery flow test`.

---

## Self-review

- **Spec coverage:** §6 delivery routes/auth/field-selection/filtering/pagination/rate-limit/ETag → Tasks 1,4,5,7,8. §1 filterable fields require declared `filter_type` + expression indexes built out-of-band → Tasks 3,4. §4 reference projection on write + published-only resolution at read → Tasks 2,6. The leak-proof "no status column on the read path" → Task 1 (structural).
- **Deferred & labeled:** cache-tag *invalidation* / CDN purge / search reindex (Publishing Pipeline plan); preview; SPA; export. This plan emits ETag/cache-tags but does not invalidate them.
- **Verify-points (confirm-then-implement against the real API, named inline):** query-builder `join`/alias form (Task 1); `Glueful\Queue\Job` base + `QueueManager::push` (Task 3); raw non-transactional PDO exec for `CREATE INDEX CONCURRENTLY` (Task 3); `FieldSelector`/`Projector`/`#[Fields]` invocation + `FieldSelectionMiddleware` (Task 7); the Lemma `require_content_scope` middleware reads its param + `api_key_scopes` + `ApiKeyService::scopeSatisfies` (fail-closed — core's `require_scope` fluent form is a no-op) + API-key minting (Tasks 7,8). Each names exactly what to confirm and the fallback.

- **Scope gate is fail-CLOSED (P1 fix):** v1 delivery uses the Lemma `require_content_scope` middleware (not core's fluent `require_scope`, which ignores params and falls through). Public/anonymous-per-type access is deferred to post-v1 (needs a conditional-access middleware + dropping group-level `auth`); v1 is uniformly API-key gated.
- **Security:** delivery is read-only; field names interpolated into JSONB expressions are always the schema-validated `[a-z][a-z0-9_]*` names (asserted again at the SQL-building sites), values are always bound placeholders — no injection surface; scopes gate the routes; unindexed fields are not filterable (latency predictability + no accidental full-scan DoS).
- **One foundation modification:** `EntryRepository.saveDraft` gains the reference-projection rebuild inside its transaction (Task 2), and `ContentTypeController` enqueues the index job (Task 3). Both additive; the foundation's tests must stay green after the constructor change.
