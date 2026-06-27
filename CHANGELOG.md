# Changelog

All notable changes to this Glueful API application will be documented in this file.

This project is generated from `glueful/api-skeleton`. Start recording application-specific changes here after scaffolding.

## [Unreleased]

### Added

#### Delivery API (`/v1/content`)
- Public, read-only delivery of **published content only**. `DeliveryRepository` reads
  exclusively through `entry_publications ⋈ entry_versions ⋈ entries[status=active]` — there is
  no draft/status column on the read path, so drafts physically cannot leak.
- Admin deletion and routing endpoints for content types and entries: discard working drafts,
  soft-delete content types/entries, list published versions, and assign/list/remove entry routes.
- Delivery access gate with both global (`read:content`) and per-content-type
  (`read:content:{type}`) API-key scopes, plus per-type public delivery opt-in via
  `content_types.public_delivery`. Invalid supplied API keys still fail 401 and never fall
  through to public access.
- `FilterCompiler`: safe, typed, filterable-only JSONB filter predicates
  (`?filter[field][op]=value`) with always-bound values, sharing a `FieldSqlExpression` helper
  with the expression-index planner so predicates always hit their index.
- Filterable-field expression-index lifecycle: a queued `EnsureFilterIndexesJob` builds Postgres
  expression indexes (`CREATE INDEX CONCURRENTLY`) out-of-band; a registry table tracks them.
- `SortCompiler` + keyset (cursor) pagination, stable under publish churn (`v.id` tiebreaker).
  Sorting on an optional filterable field pins missing-value rows last (`NULLS LAST`, both
  directions) and the keyset predicate mirrors that, so rows missing the sorted field are never
  skipped across page boundaries. Framework offset pagination backs the `?page`/`?perPage` path.
- `ReferenceResolver`: batch-loaded, published-only resolution of entry-UUID references at read
  time (unpublished/archived targets resolve to `null`; depth-bounded).
- Field selection / `ETag` / `Cache-Tag` (`lemma:entry:{uuid}`, `lemma:type:{slug}`) /
  `Cache-Control` on delivery responses.
- SEO/routing module: route slug changes auto-capture 301 redirects, admins can manage manual
  internal/external redirects, single-entry delivery emits canonical/hreflang metadata, and moved
  paths return a headless redirect descriptor (`data.redirect`) instead of serving duplicate
  content at the old path.

#### Import/export
- `lemma.content` export adapter for `glueful/import-export`, registered through
  `import_export.exporter`. It writes deterministic NDJSON batch files containing content types,
  entries, drafts, versions, publications, routes, and reference/asset projections as
  `{kind, data}` records.
- `lemma.content` import adapter for `glueful/import-export`, registered through
  `import_export.importer`. It supports dry-run validation and commit-mode idempotent upserts of
  Lemma content NDJSON bundles by each record kind's natural key.
- Content-import adapters that create entries of a chosen content type from foreign formats, each
  driven by a field-mapping wizard on the Import/Export settings page (dry-run validates without
  writing; commit creates drafts and optionally publishes):
  - `csv.content` — one entry per CSV row, fields mapped to columns.
  - `markdown.content` — a Markdown/MDX document with front matter; the body is converted to HTML
    into a chosen field.
  - `wordpress.content` — a WordPress export (WXR); each `post`/`page` `<item>` becomes an entry,
    with scalar WXR keys (`title`, `excerpt`, `slug`, `date`, `status`, `author`) mapped to fields
    and `content:encoded` HTML routed into a chosen body field. Items with WXR status `publish` are
    published on commit. Attachments and custom post types are skipped.
- `csv.users` import adapter (and a bulk-import modal on the Users page) for creating users and
  their profiles from a CSV. Built on a reusable `AbstractCsvImporter` base that the content and
  user CSV importers share.

#### Localization
- i18n-backed content locale validation through `ContentLocaleService`: when `glueful/i18n` is
  installed, authoring, publishing, routing, and preview-mint locale params must be enabled i18n
  locales; without i18n, Lemma falls back to `lemma.default_locale`.
- Entry locale variant workflow endpoints: `GET /v1/admin/entries/{uuid}/locales` summarizes each
  locale's draft/publication/route state, and `POST /v1/admin/entries/{uuid}/locales/{locale}`
  creates a target-locale draft, optionally copied from a source locale draft.
- Field-level localization automation: source-locale copy now preserves non-localized/shared fields
  by key presence while leaving `localized: true` fields empty for translation.
- Per-locale RBAC support through Aegis resource-filtered grants: locale-targeted admin routes
  now authorize against `locale:<code>` while locale-agnostic routes keep the coarse `lemma`
  resource. Seeded unscoped roles remain backward compatible.

#### Publishing pipeline
- A frozen PSR-14 content-event taxonomy (`entry.created/updated/published/unpublished/deleted`,
  `model.created/updated/deleted`, `asset.attached/detached`) with identity-only payloads
  (never full field content).
- Events dispatched from `db()->afterCommit(...)` — fire once, on the outermost commit only,
  never on rollback. Asset `attached`/`detached` deltas diffed on draft save.
- Listeners (registered in `LemmaServiceProvider::boot()`): `InvalidateCacheTagsListener`
  (invalidates the delivery cache tags), `DispatchWebhookListener` (core `WebhookDispatcher`,
  identity-only, gated by `pipeline.webhooks_enabled`), and capability-gated `PurgeCdnListener`
  / `ReindexSearchListener` (clean no-ops without `glueful/cdn` / a bound content reindexer).
- `lemma:resync` command: re-drives the idempotent effects (cache invalidation + search reindex;
  webhooks opt-in via `--webhooks`) for an entry, a type, or everything — published content only,
  bounded/keyset-paged.
- Scheduled publish/unpublish: `POST /v1/admin/entries/{uuid}/schedules/{locale}` creates or
  reschedules a pending publish/unpublish action, `GET /schedules` lists pending/history rows,
  `DELETE /schedules/{scheduleUuid}` cancels pending rows, and the every-minute
  `RunDueSchedulesJob` fires due rows through the normal `PublishService` path.

#### Version retention
- `lemma:versions:prune` operator command for manual, opt-in pruning of non-pinned
  `entry_versions` history, with `--dry-run`, `--keep`, and `--max-age-days` controls. Pinned
  publications are protected by a delete-time guard, and unset retention config preserves
  unlimited history.

#### Schema migrations
- Explicit destructive schema migrations for content types: `POST /v1/admin/content-types/{slug}/migrations`
  accepts tracked `rename` and `delete` field operations, records progress in
  `entry_schema_migrations`, flips the canonical schema immediately, and queues
  `lemma:schema:backfill` materialization.
- Read-time schema projection now replays pending migration operations for lagging drafts,
  versions, preview tokens, and delivery rows, so partially materialized catalogs still serve
  the current schema shape. Published backfills append and re-pin new migrated versions while
  preserving historical version rows.

#### Preview tokens
- HMAC-signed (`APP_KEY`) `PreviewToken` bound to `{entry, locale, version?}` with a minutes-scale
  TTL — signature verified constant-time before any payload is trusted; `exp` is inside the signed
  payload (no lifetime extension).
- Admin mint endpoint `POST /v1/admin/entries/{uuid}/preview/{locale}` (auth + `lemma_permission`);
  public `GET /v1/preview/{token}` — unauthenticated by design (the token is the capability),
  rate-limited by IP, fail-closed (invalid/malformed → 403, expired → 410, target gone → 404).
  Serves the entry's current draft, or a specific pinned version (bound to the token's entry+locale).
  This is the **only** door to a draft; the public delivery API can never see drafts.

### Changed
- `FieldValidator` normalizes `datetime` field values to canonical ISO-8601 UTC
  (`YYYY-MM-DDTHH:MM:SSZ`) on write, keeping stored values lexicographically comparable
  as text for the datetime expression index and filter range comparisons.
- `PublishService` now rebuilds the `entry_references` projection from the published version
  snapshot on publish/rollback, so draft edits never affect delete protection or delivery-time
  reference resolution until they are actually published.
- `EntryRepository::saveDraft` debounces `entry.updated`: successful saves that write the same
  field payload no longer emit redundant update events.
- `PublishService` now reserves the next immutable version number under a transaction-scoped
  advisory lock per entry+locale before appending the version row.
- `FieldValidator` validates `asset` fields against active core `blobs` on the configured
  `lemma.media_disk`, instead of accepting any non-empty UUID-shaped string.
- `RequireLemmaPermission` resolves the authenticated principal from the post-auth `user` request
  attribute set by `AuthMiddleware` (falling back from the optional `auth.user` enricher), so every
  `lemma_permission`-gated admin route authorizes correctly in a lean install — still fail-closed
  (no principal or missing grant → 403).
- Content types now carry an optional `cache_ttl` override, and delivery responses use it for
  `Cache-Control` max-age before falling back to `lemma.delivery.cache_ttl`.
- Single-entry delivery now uses `glueful/i18n` locale fallback chains when available: `show`
  resolves route slugs and entry UUIDs through the requested locale's fallback chain while
  preserving the actual served locale in the response payload.
- `EntryRepository::softDelete` now emits `AssetDetached` for the deleted entry's current asset
  references before emitting `EntryDeleted`, keeping asset usage webhooks consistent with draft-save
  asset deltas.
- `ReindexSearchListener` now calls Lemma's provider-neutral `ContentReindexerInterface` seam, so
  any search extension can bind the reindexer and own its own queueing/document shape without Lemma
  referencing a vendor-specific job class.
- PHPUnit now pins `DB_DRIVER=pgsql`, and the repository ships a GitHub Actions CI workflow that
  runs the Composer CI gate against Postgres.
