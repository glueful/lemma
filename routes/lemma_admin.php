<?php

declare(strict_types=1);

use App\Content\Http\Controllers\ContentTypeController;
use Glueful\Routing\Router;

/** @var Router $router */

$router->group(['prefix' => '/v1/admin', 'middleware' => ['auth']], function (Router $router): void {
    /**
     * @route GET /v1/admin/content-types
     * @summary List content types
     * @tag Lemma Admin
     */
    $router->get('/content-types', [ContentTypeController::class, 'index'])
        ->middleware('lemma_permission:lemma.entries.read');

    /**
     * @route POST /v1/admin/content-types
     * @summary Create content type
     * @tag Lemma Admin
     * @requestBody slug:string name:string schema:array {required=slug,name}
     */
    $router->post('/content-types', [ContentTypeController::class, 'store'])
        ->middleware('lemma_permission:lemma.models.manage');

    $router->get('/content-types/{slug}', [ContentTypeController::class, 'show'])
        ->middleware('lemma_permission:lemma.entries.read');
    $router->patch('/content-types/{slug}/schema', [ContentTypeController::class, 'updateSchema'])
        ->middleware('lemma_permission:lemma.models.manage');

    // Entry + publication routes added in Tasks 12–13.
});
