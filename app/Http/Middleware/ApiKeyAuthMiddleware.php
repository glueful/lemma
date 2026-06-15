<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Middleware\AuthMiddleware;
use Psr\Container\ContainerInterface;

/**
 * An {@see AuthMiddleware} pinned to the `api_key` provider only.
 *
 * The framework's autowired `auth` middleware accepts both `jwt` and `api_key`. The
 * delivery API (`/v1/content/*`) is API-key-only, so it uses this variant instead: the
 * request is strictly API-key authenticated and the OpenAPI reflect generator emits
 * `ApiKeyAuth` natively (no post-process security narrowing). Runtime behaviour is
 * otherwise identical — {@see \App\Content\Http\RequireContentScope} already 403s any
 * non-api-key principal.
 *
 * Registered as a plain autowired service (alias `api_key`) so it survives container
 * compilation, unlike a closure factory.
 */
final class ApiKeyAuthMiddleware extends AuthMiddleware
{
    public function __construct(
        ?ContainerInterface $container = null,
        ?ApplicationContext $context = null,
    ) {
        parent::__construct(null, $container, ['api_key'], [], $context);
    }
}
