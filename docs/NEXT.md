# What's Next — Lemma forward-work index

> A **pointer page**, not a design doc or a backlog. It records that the POST‑V1 backlog is
> closed and links each remaining thread to where it is *already* documented, so nothing has
> to be rediscovered. No work is sketched or decided here — follow the links.

**POST‑V1 status:** ✅ **closed** — all six deferred features shipped (2026‑06‑17). See
[POST_V1.md](POST_V1.md). The product lineage is
[APPROACH.md](APPROACH.md) (vision) → [V1_DESIGN.md](V1_DESIGN.md) (V1 architecture
decisions); the next phases derive from the same APPROACH.

---

## Large tracks (named in the vision, not yet started)

Each already has a home — there is **no** new doc to write to *track* these:

| Track | Where it lives today | Shape |
|-------|----------------------|-------|
| **Admin SPA** — Vue 3 + Vite + Nuxt UI editor UI against the admin API | [APPROACH.md](APPROACH.md) §"Admin Interface" (stack, `public/admin` packaging rule, in‑memory‑token auth posture) | Frontend deliverable → needs an **implementation plan**, not an architecture doc (architecture already decided in APPROACH) |
| **Content importers** — WordPress / Markdown‑MDX / CSV → entry mapping | [ADAPTER_NOTES.md](ADAPTER_NOTES.md) (adapters over the built `glueful/import-export` engine) | Per‑adapter specs/plans |
| **Multi‑tenancy** — tenant‑owned content via `glueful/tenancy` | [V1_DESIGN.md](V1_DESIGN.md) §10 "Multi‑tenancy: deliberately not in the v1 schema" — the bounded, additive retrofit path + the guardrails that keep it cheap | Backend retrofit (add `tenant_uuid`, widen unique constraints, `BelongsToTenant` trait) — no row‑identity change |

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
- **Forms**, **navigation / menu builder**, **taxonomies / collections** — feature modules.
- **Ecommerce content integration**, **personalization / segmentation** — later, per APPROACH.

---

## Recommended sequencing (opinion, not a commitment)

1. **Admin SPA next.** V1 is a complete headless backend that *no human can use yet*; the
   editor UI is what makes Lemma "usable out of the box" (APPROACH §"Admin Interface") and
   unblocks real dogfooding. It needs a **plan**, not a `V2_DESIGN.md`.
2. **`V2_DESIGN.md` for rendered delivery** — write it when rendered delivery becomes the
   active phase, since that is where the next expensive‑to‑reverse decisions live.
3. Everything else (importers, tenancy, the per‑feature follow‑ups) is pull‑based: pick one,
   run the proven loop — brainstorm → spec → plan → implement — starting from the linked home
   above.
