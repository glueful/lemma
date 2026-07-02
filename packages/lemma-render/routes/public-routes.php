<?php

declare(strict_types=1);

use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Glueful\Lemma\Render\Http\Middleware\RenderPageCache;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * The rendered site surface (loads only when lemma.render is enabled). GET /{path} with
 * a slash-spanning constraint lives in the router's '*' bucket — tried after every
 * static route and literal-first-segment bucket, i.e. a TRUE lowest-priority catch-all
 * (V2 §2). The controller's reserved-path guard returns standard JSON 404s for /v1 etc.
 *
 * Deliberately NO rate_limit on page views: this is the whole-site surface, not an API.
 * The abuse posture is the render cache (RenderPageCache per-path 200s + the fixed
 * single-body 404/410 keys in RenderErrorCache — bogus paths can't fill the cache or
 * re-render templates).
 */
// Preview-through-theme (preview spec §1): the signed token IS the authorization.
// Deliberately NO RenderPageCache middleware — the cache bypass is structural; a
// preview response can never enter or read the shared page cache. The static first
// segment wins over the '*'-bucket catch-all.
$router->get('/_preview/{token}', [RenderController::class, 'preview']);

$router->get('/', [RenderController::class, 'home'])
    ->middleware([RenderPageCache::class]);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+')
    ->middleware([RenderPageCache::class]);
