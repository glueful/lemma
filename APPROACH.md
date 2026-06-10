# Lemma Approach Notes

Lemma is a standalone content platform built on Glueful. It should feel like its own product, not just a framework extension, while still taking full advantage of Glueful's core and extension ecosystem.

## Product Direction

Lemma is a hybrid CMS:

- headless when teams want structured content delivered through APIs;
- rendered when teams want the CMS to serve websites and pages directly;
- structured enough for serious content modeling;
- practical enough for websites, ecommerce content, marketing pages, docs, and app content.

The core idea is:

> The canonical source for your content. Model it once with flexible schemas, manage it with real editorial workflows, and deliver it anywhere, rendered directly or through headless APIs.

The word "canonical" matters. Lemma should be the source of truth for content entries, fields, relationships, media, workflow state, and delivery behavior.

## Name And Brand

The product name is Lemma.

Reasons it fits:

- short and easy to pronounce;
- connected to language and meaning;
- a lemma is the canonical form of a word, which maps well to a CMS as the canonical source of content;
- it avoids forcing Glueful into the product name, even though Glueful powers the implementation.

Domain:

- `getlemma.dev`

## Technical Foundation

Lemma should be built as a Glueful application/product, not as a separate framework.

Glueful core already covers most of the infrastructure layer Lemma needs:

- routing and controllers;
- validation and DTOs;
- ORM/query builder access;
- migrations;
- auth/security primitives;
- extension loading;
- queue primitives;
- scheduler primitives;
- upload/blob storage;
- webhook subscriptions, delivery tracking, signing, retries, delivery jobs, and webhook docs support;
- OpenAPI generation, docs routes, Scalar/Swagger/Redoc UI, extension doc merging, and SDK client generation;
- basic audit/activity logging through `activity_logs`, database log handling, auth/security event logging, and audit context helpers.

Lemma should provide:

- content model builder;
- entries and revisions;
- publishing workflow;
- preview and rendering behavior;
- headless delivery APIs;
- admin/editor UI;
- CMS-specific asset organization;
- content relationships, localization, and CMS-specific permission behavior.

Most important takeaway: Glueful already covers the platform infrastructure well. Lemma should focus on the content domain, then integrate with Glueful core and existing extensions instead of rebuilding storage, webhooks, scheduler, OpenAPI, or basic audit logging.

## Database

PostgreSQL should be the primary database target.

Reasons:

- strong relational modeling for content types, entries, revisions, users, workflows, and permissions;
- JSONB support for flexible schema payloads where useful;
- good indexing for search/filter workloads;
- mature transaction semantics for publishing and revision flows;
- good fit for future multi-tenant and ecommerce workloads.

The schema should avoid becoming a pure JSON document store. Use relational tables for durable system concepts, and use JSONB selectively for flexible content field values or schema metadata.

## Storage And Media

Lemma should consume Glueful's core upload/blob system instead of building its own storage layer.

Glueful core already has:

- `StorageManager`;
- Flysystem disks;
- local and memory disk support;
- configured S3/Azure/GCS drivers when the relevant adapters are installed;
- blob metadata table;
- blob routes;
- signed URLs;
- upload handling.

Recommended direction:

- store media references as blob UUIDs;
- use a Lemma media disk setting, likely `lemma.media_disk`, that maps to a Glueful storage disk;
- rely on `glueful/media` for image transforms, thumbnails, and metadata;
- rely on `glueful/cdn` for CDN purge/invalidation;
- rely on future storage provider factories for S3, R2, GCS, Azure, SFTP, or other disks.

Storage provider packs are convenience and packaging improvements, not a reason for Lemma to own storage. They should not duplicate uploads, blob schema, blob routes, or media processing.

## Already Available As Extensions

Useful existing extensions:

- `glueful/users` - identity/auth and user store.
- `glueful/entrada` - identity/auth product surface.
- `glueful/aegis` - permissions/security product surface.
- `glueful/tenancy` - future multi-tenant CMS and agency/customer installs.
- `glueful/media` - rich media processing.
- `glueful/cdn` - CDN and edge cache purge.
- `glueful/meilisearch` - search indexing.
- `glueful/queue-ops` - queue supervision and worker operations.
- `glueful/email-notification` - email delivery.
- `glueful/notiva` - notifications.
- `glueful/conversa` - conversations/messaging if needed.
- `glueful/payvia`, `glueful/commerce`, `glueful/subscriptions` - ecommerce, paid membership, plans, gated content, and commerce-related workflows.
- `glueful/archive` - archive/lifecycle use cases.
- `glueful/runiva` - operational/background execution use cases.

Lemma should integrate with these where they solve a real CMS need, but not require all of them for a basic install.

## Do Not Rebuild As Extensions

These are already covered well enough in framework core and should not be treated as separate required extensions for Lemma:

- storage/uploads, except provider-pack convenience packages;
- webhooks;
- scheduler;
- OpenAPI/docs;
- basic audit/activity logging.

## Good For The Glueful Ecosystem

These would benefit many Glueful apps, including Lemma, but they should be filtered by boundary and demand. First-party extensions should be primitives that integrate with core seams, not full product surfaces that compete with dedicated tools.

Near-term first-party primitives:

- Storage provider packs: S3, GCS, Azure, SFTP later; R2, MinIO, Spaces, and Wasabi are S3-compatible presets, not separate packs.
- Feature flags: rollout checks, audience targeting, environment flags, and a null/default provider.
- Localization/i18n: locales, translation catalogs, fallback rules, regional formatting, and a base layer that Lemma content localization can build on.
- Import/export engine: generic CSV, JSON, NDJSON, ZIP/bundle jobs, validation reports, batching, queue integration, and export packaging.

Demand-gated primitives:

- Backup: disaster-recovery workflows such as database dumps, blob/storage backup, encrypted archives, and restore. Keep this distinct from `glueful/archive`, which is lifecycle/cold-storage behavior.
- Advanced audit: immutable audit trails, diffs, retention, tamper checks, admin UI, and export. Core logging is enough for basic audit; build this only when a compliance-grade consumer exists.

Split or defer:

- Analytics/events should be split. A first-party event stream/capture extension is reasonable: emit, store, forward to sinks, and export. Funnels, dashboards, and product analytics UI should be deferred or left to integrations such as PostHog-style tools.

Boundary notes:

- `backup` and `archive` are different: backup is disaster recovery; archive is lifecycle/cold storage.
- `advanced audit` and core `activity_logs` are different: audit is compliance-grade history; activity logs are operational history.
- `import-export` and Lemma importers are different: import/export is the engine; Lemma owns CMS-specific adapters like WordPress, Markdown/MDX, and CSV-to-entry mapping.
- `flags` and entitlements are different: flags decide rollout/audience exposure; entitlements decide whether a tenant/account is allowed to use a commercial capability.

## Lemma-Specific Domain

These should be Lemma core or Lemma-owned modules/extensions:

- content models and fields;
- entries and revisions;
- draft/publish workflow;
- review/approval workflow;
- preview system;
- block/page builder;
- taxonomies and collections;
- public content delivery API;
- admin/editor API;
- content routing/rendering;
- SEO metadata, redirects, sitemap;
- publishing pipeline integrating core webhooks, scheduler, CDN, search, and queues;
- content migrations/importers for WordPress, Markdown/MDX, and CSV;
- localization for content, possibly built on a future Glueful i18n extension;
- forms;
- navigation/menu builder;
- ecommerce content integration;
- personalization/segmentation later.

## Core Improvements Before Storage Packs

The storage discussion produced one immediate fix and one larger design direction.

Already fixed in Glueful core:

- `FileUploader` now records the actual effective upload disk in `blobs.storage_type`, so explicit per-request storage drivers are not overwritten by `uploads.disk`.

Future core storage registry work:

- introduce `StorageDriverFactoryInterface`;
- introduce `StorageDriverRegistryInterface`;
- register storage factories through a `storage.driver_factory` tagged iterator;
- keep factory capabilities explicit and optional;
- use `NativeSignedUrlProviderInterface` for provider-native temporary URLs;
- use `StorageHealthCheckInterface` for diagnostics;
- default `supports_atomic_move` to `true`;
- let object-store drivers opt out of atomic moves;
- preserve `StorageManager` constructor compatibility with a nullable/default registry.

Storage provider packs should build on that seam. They are useful for Glueful and Lemma, but Lemma itself should continue to consume the core blob/storage abstraction.

## Initial Product Shape

The first usable Lemma version should focus on:

1. content types and fields;
2. entries with draft/published state;
3. revisions;
4. media attachment through Glueful blobs;
5. headless read APIs;
6. basic rendered pages;
7. PostgreSQL migrations;
8. user roles and permissions;
9. admin/editor UI;
10. OpenAPI docs.

It should integrate with core webhooks, scheduler, OpenAPI, storage, queues, CDN, and search from the start where useful. Avoid overbuilding early: the product should prove the content model, editorial workflow, and delivery model first.
