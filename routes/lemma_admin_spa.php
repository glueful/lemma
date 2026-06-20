<?php

declare(strict_types=1);

use App\Content\Http\Controllers\AdminConfigController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * Admin runtime config — UNAUTHENTICATED by design. config.json is fetched by the SPA before
 * login. Auto-discovered by RouteManifest; the provider must NOT loadRoutesFrom() this file
 * (it would double-register).
 *
 * The compiled bundle itself (/admin + /admin/{rest}) is NOT served here — it is mounted by
 * the framework's serveFrontend() seam in LemmaServiceProvider::boot() (Task 0c). config.json
 * is a STATIC route, so the router's O(1) static-first lookup always matches it before
 * serveFrontend's dynamic /admin/{rest} catch-all — it is never swallowed by the SPA fallback.
 *
 * This route is NOT under /v1/admin and carries no `auth` middleware; every API call the SPA
 * makes IS auth-gated under /v1/admin.
 */
$router->get('/admin/config.json', [AdminConfigController::class, 'config']);
