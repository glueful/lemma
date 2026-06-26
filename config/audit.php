<?php

/**
 * App-level overrides for the glueful/audit extension.
 *
 * Keys here are deep-merged OVER the extension's own config/audit.php defaults,
 * so this file only needs to carry the values Lemma changes — everything else
 * (capture toggles, ignore_tables, retention, …) keeps the extension defaults.
 */

declare(strict_types=1);

return [
    // Generic blob uploads land in the `blobs` table, which the subscriber would
    // otherwise file under the catch-all `data` category. Lemma surfaces blobs
    // through its Media Library, so audit them as `media` to match (the audit-log
    // filter dropdown lists this category — see admin/src/queries/audit.ts).
    'category_map' => [
        'blobs' => 'media',
    ],
];
