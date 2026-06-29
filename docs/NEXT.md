# What's Next — Lemma forward-work index

> A **pointer page**, not a design doc or a backlog. It records that the POST‑V1 backlog is
> closed and links each remaining thread to where it is *already* documented, so nothing has
> to be rediscovered. No work is sketched or decided here — follow the links.

**POST‑V1 status:** ✅ **closed** — all six deferred features shipped (2026‑06‑17). See
[POST_V1.md](POST_V1.md). The product lineage is
[APPROACH.md](APPROACH.md) (vision) → [V1_DESIGN.md](V1_DESIGN.md) (V1 architecture
decisions); the next phases derive from the same APPROACH.

**Shipped since (2026‑06):**
- **Admin SPA** — the Vue 3 + Vite + Nuxt UI editor UI is built out: content list + entry
  editor (rich text, references, versions, redirects), media, users (+ bulk CSV import), roles/
  permissions, webhooks, API keys, audit log, extensions, settings, utilities, auth/setup flows.
  Remaining SPA work is polish, not net-new surfaces. Tracked in `docs/superpowers/plans/*admin-spa*`.
- **Content importers** — `csv.content`, `markdown.content`, `wordpress.content` (WXR, v1: posts/
  pages → entries) and `csv.users`, over the `glueful/import-export` engine with a mapping wizard.
  Depth follow-ups remain (see below). Tracked in [ADAPTER_NOTES.md](ADAPTER_NOTES.md).
- **Localization UI** — editor locale workflow complete: per-locale publish/draft/scheduled status
  in the switcher, locale-aware versions page, copy-into-existing-locale (overwrite), translation-
  coverage in the entry list, cross-locale route management, bulk create/publish, and a disable-
  locale guard (`GET /v1/admin/locales/{locale}/usage`). Plan: `docs/superpowers/plans/2026-06-27-finish-localization-ui.md`.
- **Multi‑valued + filterable references** — reference fields can hold an array of targets
  (`multiple`/`maxItems`), resolve targets by uuid **or** a configurable slug field
  (`referenceSlugField`), and be filtered at the delivery layer via JSONB array containment over
  the published spine (GIN `jsonb_path_ops` expression index, auto-planned per content type).
  Admin: `MultiReferencePicker`. This is the **taxonomy enabler** — categories/tags now model as
  content-type-as-terms + a reference filter. Spec:
  `docs/superpowers/specs/2026-06-27-multivalued-filterable-references-design.md`; plan:
  `docs/superpowers/plans/2026-06-27-multivalued-filterable-references.md`.

---

## Large tracks (named in the vision, not yet started)

Each already has a home — there is **no** new doc to write to *track* these:

| Track | Where it lives today | Shape |
|-------|----------------------|-------|
| **Multi‑tenancy** — tenant‑owned content via `glueful/tenancy` | [V1_DESIGN.md](V1_DESIGN.md) §10 "Multi‑tenancy: deliberately not in the v1 schema" — the bounded, additive retrofit path + the guardrails that keep it cheap | Backend retrofit (add `tenant_uuid`, widen unique constraints, `BelongsToTenant` trait) — no row‑identity change |

### Importer depth follow-ups (adapters shipped; these extend them)

Over the built adapters ([ADAPTER_NOTES.md](ADAPTER_NOTES.md)):
- **WordPress depth** — media/attachments, authors, **categories/tags** (now unblocked — model
  them as a terms content type + multi-valued reference fields, shipped above), custom post types,
  post meta, and upsert-by-WP-id (re-import idempotency). v1 is posts/pages only.
- **CSV / Markdown upsert-by-key** — both are create-only today; ADAPTER_NOTES recommends stable
  key-column upserts.

## Per‑feature follow‑ups (each lives in its shipped spec's "Out of scope / follow‑ups")

The six shipped features each deferred a smaller follow‑up; they are tracked *in place*:

| Feature | Deferred follow‑up | Spec |
|---------|--------------------|------|
| Destructive‑schema backfill | **retype** ops (only delete + rename shipped) | [spec](superpowers/specs/2026-06-16-destructive-schema-backfill-design.md) |
| Field‑localization | **copy‑on‑change** sync of non‑localized fields | [spec](superpowers/specs/2026-06-16-field-localization-design.md) |
| Version pruning | **scheduled pruning** + an export‑before‑prune interlock | [spec](superpowers/specs/2026-06-16-version-pruning-design.md) |
| Per‑locale RBAC | **per‑content‑type** scoping (same Aegis mechanism) | [spec](superpowers/specs/2026-06-16-per-locale-rbac-design.md) |
| SEO / routing | **sitemaps**, SEO **meta‑fields** (title/description/OG), redirect **import/export**, `lemma:seo:check`/`redirects:prune` | [spec](superpowers/specs/2026-06-16-seo-routing-module-design.md) |
| Scheduled publish | **auto‑retry** of failed schedules, **recurring** schedules, failure notifications | [spec](superpowers/specs/2026-06-16-scheduled-publish-design.md) |

## Larger product surface still in the vision (no design yet)

These are named in [APPROACH.md](APPROACH.md) §"Lemma‑Specific Domain" / §"Initial Product
Shape" as post‑V1 and have **no** design doc yet:

- **Rendered delivery** — templates/themes/page rendering (the "rendered" half of the hybrid
  CMS). APPROACH calls this *"its own phase with its own design."* **This is the one track
  that genuinely warrants a `V2_DESIGN.md`** (rendering model, theme/template storage,
  route→render path, render caching) — write it when this phase is actually picked up.
- **Block / page builder** — architectural (how blocks compose + persist).
- **Approval / review workflow** — a state machine layered on draft/publish.
- **Localization UI** — ✅ **shipped** (2026‑06‑27). The visual locale workflow is complete; see
  the "Shipped since" list above. Remaining localization work is the *field-localization
  copy-on-change* follow-up in the per-feature table below, not editor UX.
- **Taxonomies / collections** — the underlying **reference primitive shipped** (multi-valued +
  filterable references, above), so categories/tags already work as content-type-as-terms today.
  What remains is the **delivery surface**: term-archive endpoints + facet counts over a
  *published*-reference projection, an additive layer the references spec explicitly deferred.
- **Forms**, **navigation / menu builder** — feature modules.
- **Ecommerce content integration**, **personalization / segmentation** — later, per APPROACH.

---

## Recommended sequencing (opinion, not a commitment)

1. **`V2_DESIGN.md` for rendered delivery** — the next big expensive‑to‑reverse decision set
   (rendering model, theme/template storage, route→render path, render caching). This is the one
   remaining track that warrants a design doc before a plan; write it when the phase is picked up.
2. **Taxonomies → term‑archives + facets** — the reference primitive is now shipped, so this is no
   longer a from‑scratch module: it's the smaller, additive delivery surface (term‑archive
   endpoints + facet counts over a published‑reference projection) the references spec deferred.
   High reuse — it makes the references work visible to end‑users and feeds a future
   navigation/menu builder. Needs brainstorm → spec → plan.
3. Everything else (importer depth — incl. now‑unblocked WordPress categories/tags, tenancy, the
   per‑feature follow‑ups) is pull‑based: pick one,
   run the proven loop — brainstorm → spec → plan → implement — starting from the linked home
   above.
