<?php

return [
    // Glueful storage disk that backs media blob references (see docs/V1_DESIGN.md §8).
    'media_disk' => env('LEMMA_MEDIA_DISK', 'local'),

    // Seeded role names (see docs/V1_DESIGN.md §7).
    'roles' => [
        'admin' => 'lemma_admin',
        'editor' => 'lemma_editor',
        'viewer' => 'lemma_viewer',
    ],

    // Public delivery API defaults (see docs/V1_DESIGN.md §6). Delivery is private by
    // default: clients need read:content or read:content:{type}, unless a content type sets
    // public_delivery=true.
    'delivery' => [
        // Default page size when the request omits perPage.
        'default_per_page' => (int) env('LEMMA_DELIVERY_DEFAULT_PER_PAGE', 20),
        // Hard cap on page size to keep latency predictable.
        'max_per_page' => (int) env('LEMMA_DELIVERY_MAX_PER_PAGE', 100),
        // Cache-Control max-age (seconds) emitted on delivery responses when the
        // content type has no cache_ttl override.
        'cache_ttl' => (int) env('LEMMA_DELIVERY_CACHE_TTL', 60),
    ],

    // Headless SEO/routing helpers. Paths are rendered as public-site paths, never API
    // URLs. Leave public_url_base empty to return relative paths for the frontend to
    // make absolute.
    'seo' => [
        'route_template' => env('LEMMA_SEO_ROUTE_TEMPLATE', '/{locale}/{type}/{slug}'),
        'public_url_base' => env('LEMMA_PUBLIC_URL_BASE'),
        'redirect_ttl' => (int) env('LEMMA_SEO_REDIRECT_TTL', 60),
    ],

    // Preview tokens (see docs/V1_DESIGN.md). Drafts are only reachable through a
    // signed, short-lived preview token; this is its lifetime in seconds.
    'preview' => [
        'ttl_seconds' => (int) env('LEMMA_PREVIEW_TTL', 600),
    ],

    // Downstream publishing-pipeline effects (see docs/V1_DESIGN.md §5). Each listener is
    // gated here so a deployment can opt out without unwiring the event bus.
    'pipeline' => [
        // Forward content events to the core WebhookDispatcher. Deliveries only occur for
        // events that have an active subscription, so this is safe to leave on.
        'webhooks_enabled' => (bool) env('LEMMA_WEBHOOKS_ENABLED', true),
    ],

    // Scheduled publish/unpublish. The framework scheduler's per-job `enabled` key is not
    // the gate; ScheduleRunner reads this switch before firing any due rows.
    'scheduler' => [
        'enabled' => (bool) env('LEMMA_SCHEDULER_ENABLED', true),
    ],

    // Version retention / pruning. Raw env pass-through: do not cast here.
    // RetentionPolicy::fromValues() validates positive integers and treats null/'' as off.
    'versions' => [
        'retention' => [
            'keep' => env('LEMMA_VERSION_KEEP'),
            'max_age_days' => env('LEMMA_VERSION_MAX_AGE_DAYS'),
        ],
    ],
];
