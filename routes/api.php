<?php

use Glueful\Routing\Router;
use App\Controllers\WelcomeController;

/**
 * Application API Routes
 *
 * @var Router $router Router instance injected by RouteManifest::load()
 *
 * Route Prefix Options:
 *   - 'v1'                    → /v1/welcome (simple versioning)
 *   - '/api/v1'               → /api/v1/welcome (with api prefix)
 *   - api_prefix($context)    → uses config/api.php versioning settings
 *   - (no group)              → /welcome (no prefix)
 */

$router->group(['prefix' => 'v1'], function (Router $router) {
    $router->get('/status', [WelcomeController::class, 'status']);
});

$router->get('/welcome', [WelcomeController::class, 'index']);
