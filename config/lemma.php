<?php

return [
    // Default content locale. When glueful/i18n is installed the localization
    // phase binds this to i18n.default_locale; in v1 it is a plain code.
    'default_locale' => env('LEMMA_DEFAULT_LOCALE', 'en'),

    // Glueful storage disk that backs media blob references (see docs/V1_DESIGN.md §8).
    'media_disk' => env('LEMMA_MEDIA_DISK', 'local'),

    // Seeded role names (see docs/V1_DESIGN.md §7).
    'roles' => [
        'admin' => 'lemma_admin',
        'editor' => 'lemma_editor',
        'viewer' => 'lemma_viewer',
    ],
];
