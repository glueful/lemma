<?php

declare(strict_types=1);

use App\Content\Http\Controllers\DeliveryController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Delivery API — serves ONLY published content (V1_DESIGN §6). Requests may use a valid
 * API key with `read:content` or `read:content:{type}`. Anonymous reads are allowed only
 * when the requested content type explicitly sets `public_delivery=true`; a supplied but
 * invalid API key still fails 401 and never falls through to public delivery.
 *
 * Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file
 * (double registration throws on duplicate static routes).
 */
$router->group(['prefix' => '/v1/content', 'middleware' => ['optional_api_key']], function (Router $router): void {
    // List published entries of a content type.
    $router->get('/{type}', [DeliveryController::class, 'index'])
        ->middleware('lemma_delivery_access')
        ->middleware('rate_limit')
        ->rateLimit(120, 1, by: 'user');

    // Get a single published entry by slug or UUID.
    $router->get('/{type}/{slugOrUuid}', [DeliveryController::class, 'show'])
        ->middleware('lemma_delivery_access')
        ->middleware('rate_limit')
        ->rateLimit(120, 1, by: 'user');
});
