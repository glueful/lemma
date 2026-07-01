<?php

declare(strict_types=1);

use Glueful\Lemma\Seo\Http\Controllers\RobotsController;
use Glueful\Lemma\Seo\Http\Controllers\SeoMetaController;
use Glueful\Lemma\Seo\Http\Controllers\SitemapController;
use Glueful\Routing\Router;

/** @var Router $router */

// Public SEO meta for the frontend <head>. No auth — published content only.
$router->get('/v1/seo/meta/{type}/{slug}', [SeoMetaController::class, 'show']);

// Sitemaps. Public, raw XML. Adaptive root + numbered page files.
$router->get('/sitemap.xml', [SitemapController::class, 'index']);
$router->get('/sitemap/{n}.xml', [SitemapController::class, 'page'])->where('n', '\d+');

// robots.txt. Public, plain text.
$router->get('/robots.txt', [RobotsController::class, 'show']);
