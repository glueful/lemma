<?php

declare(strict_types=1);

use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Glueful\Routing\Router;

/** @var Router $router */

/*
 * The rendered site surface (loads only when lemma.render is enabled). GET /{path} with
 * a slash-spanning constraint lives in the router's '*' bucket — tried after every
 * static route and literal-first-segment bucket, i.e. a TRUE lowest-priority catch-all
 * (V2 §2). The controller's reserved-path guard returns standard JSON 404s for /v1 etc.
 *
 * Deliberately NO rate_limit on page views: this is the whole-site surface, not an API;
 * the abuse posture (and caching) belongs to render sub-project 3.
 */
$router->get('/', [RenderController::class, 'home']);
$router->get('/{path}', [RenderController::class, 'page'])
    ->where('path', '.+');
