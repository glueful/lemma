<?php

declare(strict_types=1);

namespace App\Content\Http;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Requires the request's API key to hold a specific content scope (e.g. `read:content`).
 *
 * Registered under the `require_content_scope` alias for routes that need a static content
 * scope. Delivery itself now uses DeliveryAccessMiddleware because it also supports
 * per-type scopes and public content-type opt-in.
 *
 * Why this exists instead of the framework's RequireScopeMiddleware: that middleware is
 * attribute-only and fails OPEN for fluent file routes — it reads the route's
 * `getRequireScopeConfig()`, ignores `...$params`, and falls through to `$next` when the
 * route carries no attribute config. Lemma's delivery routes are file routes, so we read
 * the required scope from the first middleware parameter and fail CLOSED: a missing/empty
 * scope param, an unauthenticated request (no `api_key_scopes` attribute), or an
 * insufficient grant all return 403.
 */
final class RequireContentScope implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $required = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';
        if ($required === '') {
            return Response::forbidden('Scope required');
        }
        if (!$request->attributes->has('api_key_scopes')) {
            return Response::forbidden('This route requires a scoped API key');
        }
        /** @var list<string> $granted */
        $granted = array_values(array_filter(
            (array) $request->attributes->get('api_key_scopes', []),
            'is_string'
        ));
        if (!ApiKeyService::scopeSatisfies($granted, $required)) {
            return Response::forbidden('Insufficient scope: ' . $required);
        }
        return $next($request);
    }
}
