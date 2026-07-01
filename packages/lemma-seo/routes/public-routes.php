<?php

declare(strict_types=1);

use Glueful\Lemma\Seo\Http\Controllers\SeoMetaController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public SEO meta for the frontend <head>. No auth — published content only.
$router->get('/v1/seo/meta/{type}/{slug}', [SeoMetaController::class, 'show']);
