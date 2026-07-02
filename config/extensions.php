<?php

/**
 * Extensions
 *
 * Composer discovers installed `glueful-extension` packages (see their
 * extra.glueful.provider). This file is the single activation allow-list:
 * an installed extension does nothing until its provider FQCN appears below.
 *
 * - Entries are plain string FQCNs (no ::class) so `php glueful extensions:enable|disable`
 *   can edit this list safely. Do not use conditionals/function calls here.
 * - Order is preserved; dependencies are reordered automatically.
 * - Empty = nothing loads. To kill everything fast, set `enabled => []`.
 *
 * Manage with: php glueful extensions:list | enable <name> | disable <name> | cache
 */

return [
    'enabled' => [
        'Glueful\Extensions\Aegis\Services\AegisServiceProvider',
        'Glueful\Extensions\Audit\AuditServiceProvider',
        'Glueful\Extensions\EmailNotification\EmailNotificationServiceProvider',
        'Glueful\Extensions\I18n\I18nServiceProvider',
        'Glueful\Extensions\ImportExport\ImportExportServiceProvider',
        'Glueful\Extensions\Media\MediaServiceProvider',
        'Glueful\Extensions\Meilisearch\MeilisearchProvider',
        'Glueful\Extensions\Users\UsersServiceProvider',
        'Glueful\Lemma\Analytics\LemmaAnalyticsServiceProvider',
        'Glueful\Lemma\Collections\LemmaCollectionsServiceProvider',
        'Glueful\Lemma\Importers\LemmaImportersServiceProvider',
        'Glueful\Lemma\Seo\LemmaSeoServiceProvider',
        'Glueful\Lemma\Workflow\LemmaWorkflowServiceProvider',
    ],
];
