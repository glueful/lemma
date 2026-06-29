<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Http;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Default-deny scope gate for the public collections data API.
 *
 * Registered under the alias 'collection_scope'.
 *
 * Requires the request's API key to carry a scope that satisfies the operation:
 *   GET                         → collections.{name}.read
 *   POST / PATCH                → collections.{name}.write
 *   DELETE                      → collections.{name}.delete
 *
 * The collection name is read from the '_route_params' request attribute that the
 * router injects before middleware runs. Fail-closed: a missing name, missing scopes
 * attribute, or insufficient grant all return 403.
 *
 * Why fail-closed rather than delegating to a public-opt-in flag: collections are
 * developer-defined data tables that may hold sensitive application state. Requiring
 * an explicit grant per collection is safer than an opt-out public flag.
 */
final class CollectionScopeMiddleware implements RouteMiddleware
{
    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Fail closed: no api_key_scopes attribute means the request is anonymous
        // (OptionalApiKeyAuthMiddleware only sets it when a key was presented and validated).
        if (!$request->attributes->has('api_key_scopes')) {
            return Response::forbidden('This route requires a scoped API key');
        }

        $routeParams = (array) ($request->attributes->get('_route_params') ?? []);
        $name        = (string) ($routeParams['name'] ?? '');

        if ($name === '') {
            return Response::forbidden('Collection name could not be determined');
        }

        $required = $this->requiredScope($request->getMethod(), $name);

        /** @var list<string> $granted */
        $granted = array_values(array_filter(
            (array) $request->attributes->get('api_key_scopes', []),
            'is_string',
        ));

        if (!ApiKeyService::scopeSatisfies($granted, $required)) {
            return Response::forbidden('Insufficient scope: ' . $required);
        }

        return $next($request);
    }

    /**
     * Derive the required scope string from the HTTP method and collection name.
     */
    private function requiredScope(string $method, string $name): string
    {
        $method    = strtoupper($method);
        $operation = match (true) {
            $method === 'DELETE'                => 'delete',
            in_array($method, ['POST', 'PATCH', 'PUT'], true) => 'write',
            default                             => 'read',
        };

        return 'collections.' . $name . '.' . $operation;
    }
}
