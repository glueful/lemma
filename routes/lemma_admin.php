<?php

declare(strict_types=1);

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\Controllers\PublicationController;
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
        ->middleware('lemma_permission:lemma.entries.read');

    $router->post('/content-types', [ContentTypeController::class, 'store'])
        ->middleware('lemma_permission:lemma.models.manage');

    $router->get('/content-types/{slug}', [ContentTypeController::class, 'show'])
        ->middleware('lemma_permission:lemma.entries.read');

    $router->patch('/content-types/{slug}/schema', [ContentTypeController::class, 'updateSchema'])
        ->middleware('lemma_permission:lemma.models.manage');

    $router->delete('/content-types/{slug}', [ContentTypeController::class, 'destroy'])
        ->middleware('lemma_permission:lemma.models.manage');

    // Entry authoring (identity, drafts, preview).
    $router->post('/entries', [EntryController::class, 'store'])
        ->middleware('lemma_permission:lemma.entries.write');

    $router->get('/entries/{uuid}', [EntryController::class, 'show'])
        ->middleware('lemma_permission:lemma.entries.read');

    $router->get('/entries/{uuid}/draft/{locale}', [EntryController::class, 'getDraft'])
        ->middleware('lemma_permission:lemma.entries.read');

    $router->put('/entries/{uuid}/draft/{locale}', [EntryController::class, 'saveDraft'])
        ->middleware('lemma_permission:lemma.entries.write');

    $router->delete('/entries/{uuid}/draft/{locale}', [EntryController::class, 'discardDraft'])
        ->middleware('lemma_permission:lemma.entries.write');

    $router->delete('/entries/{uuid}', [EntryController::class, 'destroy'])
        ->middleware('lemma_permission:lemma.entries.write');

    $router->get('/entries/{uuid}/versions/{locale}', [PublicationController::class, 'versions'])
        ->middleware('lemma_permission:lemma.entries.read');

    $router->get('/entries/{uuid}/routes', [EntryController::class, 'routes'])
        ->middleware('lemma_permission:lemma.entries.read');

    $router->put('/entries/{uuid}/routes/{locale}', [EntryController::class, 'assignRoute'])
        ->middleware('lemma_permission:lemma.entries.write');

    $router->delete('/entries/{uuid}/routes/{locale}', [EntryController::class, 'removeRoute'])
        ->middleware('lemma_permission:lemma.entries.write');

    $router->post('/entries/{uuid}/preview/{locale}', [PreviewController::class, 'mint'])
        ->middleware('lemma_permission:lemma.entries.read');

    // Publication lifecycle.
    $router->post('/entries/{uuid}/publish/{locale}', [PublicationController::class, 'publish'])
        ->middleware('lemma_permission:lemma.entries.publish');

    $router->post('/entries/{uuid}/unpublish/{locale}', [PublicationController::class, 'unpublish'])
        ->middleware('lemma_permission:lemma.entries.publish');

    $router->post('/entries/{uuid}/rollback/{locale}', [PublicationController::class, 'rollback'])
        ->middleware('lemma_permission:lemma.entries.publish');
});
