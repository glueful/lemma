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

**In progress:**
- **Localization UI** — finishing the editor locale workflow (the backend is done). See §"Larger
  product surface" below.

---

## Large tracks (named in the vision, not yet started)

Each already has a home — there is **no** new doc to write to *track* these:

| Track | Where it lives today | Shape |
|-------|----------------------|-------|
| **Multi‑tenancy** — tenant‑owned content via `glueful/tenancy` | [V1_DESIGN.md](V1_DESIGN.md) §10 "Multi‑tenancy: deliberately not in the v1 schema" — the bounded, additive retrofit path + the guardrails that keep it cheap | Backend retrofit (add `tenant_uuid`, widen unique constraints, `BelongsToTenant` trait) — no row‑identity change |

### Importer depth follow-ups (adapters shipped; these extend them)

Over the built adapters ([ADAPTER_NOTES.md](ADAPTER_NOTES.md)):
- **WordPress depth** — media/attachments, authors, categories/tags (needs a taxonomy model),
  custom post types, post meta, and upsert-by-WP-id (re-import idempotency). v1 is posts/pages only.
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
- **Localization UI** — the visual locale workflow (the backend is done; this is editor UX).
  **In progress** — a locale switcher, add-locale (copy-from-source) modal, shared-fields note,
  and reference picker shipped (commit 6152816). Remaining: surface the per-locale
  draft/published/scheduled status the `GET …/locales` endpoint already returns, locale-follow on
  the versions page, the `overwrite` copy option, and a translation-progress indicator in the
  entry list. See its plan in `docs/superpowers/plans/`.
- **Forms**, **navigation / menu builder**, **taxonomies / collections** — feature modules.
- **Ecommerce content integration**, **personalization / segmentation** — later, per APPROACH.

---

## Recommended sequencing (opinion, not a commitment)

1. **Finish the Localization UI.** The editor locale workflow is in flight and is the smallest
   gap to "done" — it just needs to surface backend state the API already returns. A focused
   **plan**, no new design.
2. **`V2_DESIGN.md` for rendered delivery** — write it when rendered delivery becomes the
   active phase, since that is where the next expensive‑to‑reverse decisions live.
3. Everything else (importer depth, tenancy, the per‑feature follow‑ups) is pull‑based: pick one,
   run the proven loop — brainstorm → spec → plan → implement — starting from the linked home
   above.
