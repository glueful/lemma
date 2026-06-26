<?php

declare(strict_types=1);

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\MigrationController;
use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\Controllers\RedirectController;
use App\Content\Http\Controllers\ScheduleController;
use App\Http\Controllers\EmailSettingsController;
use App\Http\Controllers\ExtensionAdminController;
use App\Http\Controllers\MediaAdminController;
use App\Http\Controllers\UserAdminController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin authoring API. Every route is gated by the `auth` middleware (a Bearer JWT or an
 * API key resolves the principal) PLUS a `lemma_permission:<permission>` RBAC check. The
 * required permission is named per route in its @description. Auto-discovered by
 * RouteManifest; the provider must NOT loadRoutesFrom() this file.
 */
$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    // Content type (model) management.
    $router->get('/content-types', [ContentTypeController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->post('/content-types', [ContentTypeController::class, 'store'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/content-types/{slug}', [ContentTypeController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->patch('/content-types/{slug}/schema', [ContentTypeController::class, 'updateSchema'])
        ->middleware('lemma_permission:content.manage');

    // Destructive schema migrations: POST body is {ops:[{op:"rename",from,to}|{op:"delete",name}]};
    // responses wrap migration rows with status/progress/failure_report for polling.
    $router->post('/content-types/{slug}/migrations', [MigrationController::class, 'store'])
        ->middleware('lemma_permission:content.manage');

    $router->get('/content-types/{slug}/migrations', [MigrationController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->get('/content-types/{slug}/migrations/{migrationUuid}', [MigrationController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->delete('/content-types/{slug}', [ContentTypeController::class, 'destroy'])
        ->middleware('lemma_permission:content.manage');

    // Entry authoring (identity, drafts, preview).
    $router->get('/entries', [EntryController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->post('/entries', [EntryController::class, 'store'])
        ->middleware('lemma_permission:content.create');

    $router->get('/entries/{uuid}', [EntryController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->get('/entries/{uuid}/draft/{locale}', [EntryController::class, 'getDraft'])
        ->middleware('lemma_permission:content.view');

    $router->put('/entries/{uuid}/draft/{locale}', [EntryController::class, 'saveDraft'])
        ->middleware('lemma_permission:content.edit');

    $router->delete('/entries/{uuid}/draft/{locale}', [EntryController::class, 'discardDraft'])
        ->middleware('lemma_permission:content.edit');

    $router->delete('/entries/{uuid}', [EntryController::class, 'destroy'])
        ->middleware('lemma_permission:content.delete');

    $router->get('/entries/{uuid}/locales', [EntryController::class, 'locales'])
        ->middleware('lemma_permission:content.view');

    $router->post('/entries/{uuid}/locales/{locale}', [EntryController::class, 'createLocaleDraft'])
        ->middleware('lemma_permission:content.create');

    $router->get('/entries/{uuid}/versions/{locale}', [PublicationController::class, 'versions'])
        ->middleware('lemma_permission:content.view');

    $router->get('/entries/{uuid}/routes', [EntryController::class, 'routes'])
        ->middleware('lemma_permission:content.view');

    $router->put('/entries/{uuid}/routes/{locale}', [EntryController::class, 'assignRoute'])
        ->middleware('lemma_permission:content.edit');

    $router->delete('/entries/{uuid}/routes/{locale}', [EntryController::class, 'removeRoute'])
        ->middleware('lemma_permission:content.edit');

    // SEO redirects: POST body is {locale, source_slug, target:{url|entry_uuid, content_type?, locale?}, status};
    // responses wrap redirect rows and their computed target_state (live|broken).
    $router->post('/content-types/{slug}/redirects', [RedirectController::class, 'store'])
        ->middleware('lemma_permission:content.routes');

    $router->get('/content-types/{slug}/redirects', [RedirectController::class, 'index'])
        ->middleware('lemma_permission:content.routes');

    $router->delete('/redirects/{uuid}', [RedirectController::class, 'destroy'])
        ->middleware('lemma_permission:content.routes');

    $router->post('/entries/{uuid}/preview/{locale}', [PreviewController::class, 'mint'])
        ->middleware('lemma_permission:content.view');

    // Scheduled publication: POST body is {action:"publish"|"unpublish", run_at:<absolute ISO-8601 with timezone>};
    // response wraps {schedule:{...row,replaced:bool}}. GET returns {schedules:[...history]}.
    $router->post('/entries/{uuid}/schedules/{locale}', [ScheduleController::class, 'store'])
        ->middleware('lemma_permission:content.publish');

    $router->get('/entries/{uuid}/schedules', [ScheduleController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->delete('/entries/{uuid}/schedules/{scheduleUuid}', [ScheduleController::class, 'destroy'])
        ->middleware('lemma_permission:content.publish');

    // Publication lifecycle.
    $router->post('/entries/{uuid}/publish/{locale}', [PublicationController::class, 'publish'])
        ->middleware('lemma_permission:content.publish');

    $router->post('/entries/{uuid}/unpublish/{locale}', [PublicationController::class, 'unpublish'])
        ->middleware('lemma_permission:content.publish');

    $router->post('/entries/{uuid}/rollback/{locale}', [PublicationController::class, 'rollback'])
        ->middleware('lemma_permission:content.publish');

    // Instance settings — mailer config persisted to .env.
    $router->get('/settings/email', [EmailSettingsController::class, 'show'])
        ->middleware('lemma_permission:content.manage');

    $router->put('/settings/email', [EmailSettingsController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    $router->post('/settings/email/test', [EmailSettingsController::class, 'test'])
        ->middleware('lemma_permission:content.manage');

    // Admin user management (app-owned policy over glueful/users' store primitives). The list/read
    // lives in glueful/users (`GET /v1/users`); creating and removing users is product policy.
    $router->post('/users', [UserAdminController::class, 'store'])
        ->middleware('lemma_permission:users.create');

    $router->patch('/users/{uuid}', [UserAdminController::class, 'update'])
        ->middleware('lemma_permission:users.edit');

    $router->delete('/users/{uuid}', [UserAdminController::class, 'destroy'])
        ->middleware('lemma_permission:users.delete');

    // Extensions — list/toggle installed glueful-extension packages + browse the Packagist catalog.
    // Enable/disable rewrites config/extensions.php (dev only). All gated by system.access.
    $router->get('/extensions', [ExtensionAdminController::class, 'index'])
        ->middleware('lemma_permission:system.access');

    $router->get('/extensions/registry', [ExtensionAdminController::class, 'registry'])
        ->middleware('lemma_permission:system.access');

    $router->post('/extensions/enable', [ExtensionAdminController::class, 'enable'])
        ->middleware('lemma_permission:system.access');

    $router->post('/extensions/disable', [ExtensionAdminController::class, 'disable'])
        ->middleware('lemma_permission:system.access');

    $router->get('/extensions/{vendor}/{name}/readme', [ExtensionAdminController::class, 'readme'])
        ->middleware('lemma_permission:system.access');

    // Media library — list/search over blobs + CMS metadata (alt/caption/tags) + usage.
    $router->get('/media', [MediaAdminController::class, 'index'])
        ->middleware('lemma_permission:content.view');

    $router->get('/media/{uuid}', [MediaAdminController::class, 'show'])
        ->middleware('lemma_permission:content.view');

    $router->get('/media/{uuid}/usage', [MediaAdminController::class, 'usage'])
        ->middleware('lemma_permission:content.view');

    $router->post('/media/{uuid}/optimize', [MediaAdminController::class, 'optimize'])
        ->middleware('lemma_permission:content.manage');

    $router->patch('/media/{uuid}', [MediaAdminController::class, 'update'])
        ->middleware('lemma_permission:content.manage');

    $router->delete('/media/{uuid}', [MediaAdminController::class, 'destroy'])
        ->middleware('lemma_permission:content.manage');
});
