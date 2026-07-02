# glueful/lemma-render

**Rendered delivery** for [Lemma](https://getlemma.dev) — the CMS serves real HTML pages
from published content through filesystem **Twig themes** — packaged as a **removable
capability pack** (V2 rendered-delivery sub-project 2; see `docs/V2_DESIGN.md`). With the
pack absent or `lemma.render` disabled, the install is exactly the headless product:
unmatched public paths return the router's standard JSON 404.

## How a page renders

One lowest-priority **catch-all** (`GET /{path}` in the router's `*` bucket — every
static route and literal-prefix bucket wins first) hands raw paths to the
**`PublicRouteResolver`** contract, which core implements over the existing routing/
addressability layer:

1. **Normalization first:** trailing/duplicate slashes 301 to the canonical path before
   any lookup.
2. **Parse** against the route template — `/{locale}/{type}/{slug}` when the first
   segment is an active locale, `/{type}/{slug}` in the default locale (`/blog/hello`
   is always type `blog`, never locale `blog`).
3. **Visibility:** render is anonymous — non-public-delivery types resolve `not_found`
   even with a live route.
4. **Resolve:** redirects honored as real 30x, broken redirect targets are 410,
   published entries return the **read-only public delivery shape** (`seo` included —
   byte-identical to the headless API) plus the content-type slug for template selection.

Reserved paths (`lemma_render.reserved_prefixes` — segment semantics — and
`reserved_exact`) return the framework's standard JSON 404 so API clients never receive
themed HTML.

## Themes

A theme is `themes/{name}/` with `theme.json`, `templates/`, `assets/`. The pack embeds
the **default reference theme**; an app theme overrides it by name
(`lemma_render.theme`, env `RENDER_THEME`) with **per-template fallback** — omit
`404.twig` and the default theme's serves. Ladder: missing app theme → default; present
but invalid `theme.json` → loud 500; broken pack default → hard 500; template missing in
both → `error.twig` → plain-text 500 (never a loop).

Hierarchy: `entry/{type-slug}.twig` → `entry.twig`; `index.twig` (homepage);
`404.twig`; `error.twig`; `layout.twig`. Context: `entry` (treat as read-only), `site`
(`name`/`locale`), and functions:

- `menu('main')` — via the `MenuReader` contract; **`[]` when lemma-navigation is absent
  or disabled** (no hard dependency).
- `path(entryUuid)` — live public path, **null unless published** (no dead links, ever).
- `asset('css/site.css')` — `/theme-assets/...`; rejects absolute URLs, `..`, leading
  `/`, backslashes. Only the active theme's `assets/` is served — never templates.

**Escaping:** the reference theme escapes everything — it cannot know that a field named
`body` is sanitized rich text. `|raw` is a deliberate opt-in for theme authors who know
their schema's rich-text fields.

Twig compiles to `storage/cache/twig/{theme}` with `auto_reload` (recompiles on template
change). **The active theme is resolved at boot (v1):** changing `lemma_render.theme`
requires an app restart / extension-cache rebuild.

## Homepage

`GET /` always renders `index.twig`. Set `lemma_render.homepage_entry` (env
`RENDER_HOMEPAGE_ENTRY`) to put that entry in the context; unset renders the standalone
welcome. A set-but-unresolvable value (missing/unpublished/routeless/deleted) is a
**500 config error** — logged always, message in the body only under debug mode.

## Config

| Key (env) | Default |
|---|---|
| `lemma_render.theme` (`RENDER_THEME`) | `default` |
| `lemma_render.homepage_entry` (`RENDER_HOMEPAGE_ENTRY`) | `''` |
| `lemma_render.site_name` (`RENDER_SITE_NAME`) | `Lemma` |
| `lemma_render.reserved_prefixes` | `v1, admin, extensions, theme-assets` |
| `lemma_render.reserved_exact` | `sitemap.xml, robots.txt` |

Page views are deliberately not rate-limited (this is the whole-site surface, not an
API); the abuse posture and full-page caching belong to render sub-project 3.

## Install / remove

Bundled by default in the Lemma create-project template. Existing app:
`composer require glueful/lemma-render`, `./lemma extensions:enable lemma-render`.
Disable via the switchboard (`'capabilities' => ['lemma.render' => false]`) or remove —
the headless product is untouched.

## Out of scope (v1 — see V2_DESIGN §6)

Render page caching (sub-project 3), listing/archive pages, taxonomy term pages,
DB-edited templates, page/block builder, preview-through-theme, admin theme/homepage
switching UI.
