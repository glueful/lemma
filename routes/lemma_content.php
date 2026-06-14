<?php

declare(strict_types=1);

use App\Content\Http\Controllers\DeliveryController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Public delivery API — serves ONLY published content (V1_DESIGN §6). Always API-key
 * gated: the `require_content_scope:read:content` middleware is the security gate and is
 * fail-CLOSED (see App\Content\Http\RequireContentScope — unlike the framework's
 * attribute-only RequireScopeMiddleware which fails open for file routes).
 *
 * Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file
 * (double registration throws on duplicate static routes).
 */
$router->group(['prefix' => '/v1/content', 'middleware' => ['auth']], function (Router $router): void {
    /**
     * @route GET /v1/content/{type}
     * @summary List published entries of a content type
     * @tag Lemma Delivery
     */
    $router->get('/{type}', [DeliveryController::class, 'index'])
        ->middleware('require_content_scope:read:content')
        ->middleware('rate_limit')
        ->rateLimit(120, 1, by: 'user');

    /**
     * @route GET /v1/content/{type}/{slugOrUuid}
     * @summary Get a single published entry by slug or uuid
     * @tag Lemma Delivery
     */
    $router->get('/{type}/{slugOrUuid}', [DeliveryController::class, 'show'])
        ->middleware('require_content_scope:read:content')
        ->middleware('rate_limit')
        ->rateLimit(120, 1, by: 'user');
});
