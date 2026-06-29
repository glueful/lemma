<?php

declare(strict_types=1);

use Glueful\Lemma\Collections\Http\Controllers\CollectionDataController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Public collections data API (§ public CRUD/query surface).
 *
 * Every route is double-gated:
 *   1. optional_api_key  — verifies the key when present; anonymous requests pass
 *                          through but carry no api_key_scopes attribute.
 *   2. collection_scope  — default-deny: requires api_key_scopes to include
 *                          collections.{name}.read|write|delete. No key or
 *                          insufficient scope → 403.
 *
 * Paths are uuid-keyed; numeric auto-increment ids are never exposed.
 */
$router->group(
    ['prefix' => '/v1/collections', 'middleware' => ['optional_api_key']],
    function (Router $router): void {
        // List rows of a collection.
        $router->get('/{name}', [CollectionDataController::class, 'list'])
            ->middleware('collection_scope');

        // Get one row by UUID.
        $router->get('/{name}/{uuid}', [CollectionDataController::class, 'show'])
            ->middleware('collection_scope');

        // Create a single row.
        $router->post('/{name}', [CollectionDataController::class, 'create'])
            ->middleware('collection_scope');

        // Bulk-create rows (all-or-nothing).
        $router->post('/{name}/bulk', [CollectionDataController::class, 'bulkCreate'])
            ->middleware('collection_scope');

        // Partially update a row by UUID.
        $router->patch('/{name}/{uuid}', [CollectionDataController::class, 'update'])
            ->middleware('collection_scope');

        // Delete a row by UUID.
        $router->delete('/{name}/{uuid}', [CollectionDataController::class, 'delete'])
            ->middleware('collection_scope');
    },
);
