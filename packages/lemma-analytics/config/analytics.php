<?php

declare(strict_types=1);

return [
    // The capability is gated; routes + listeners are active only when enabled.
    'enabled' => env('ANALYTICS_ENABLED', true),

    // Raw analytics_facts older than this many days are pruned; rollups are kept forever.
    'retention_days' => (int) env('ANALYTICS_RETENTION_DAYS', 90),

    // HMAC key for the one-way actor hash in analytics_active_actors. Falls back to APP_KEY.
    'hash_key' => env('ANALYTICS_HASH_KEY', env('APP_KEY', '')),
];
