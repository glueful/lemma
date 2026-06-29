<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Http;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\AuthenticationManager;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Lemma\Collections\Schema\AccessPolicy;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Per-collection access gate for the public collections data API. Registered as 'collection_scope'.
 *
 * The collection's own access policy (per operation: read|write|delete) decides the requirement:
 *   - public  → allowed with no authentication.
 *   - scoped  → requires the `{collection}.{action}` capability (e.g. `products.write`).
 *
 * A scoped operation is satisfied by EXACTLY ONE of two mutually-exclusive credentials:
 *   - an api-key request (api_key_scopes set by optional_api_key) → its scopes are the ONLY
 *     authority; the key never inherits its owner's broader permissions; OR
 *   - otherwise, a session user — authenticated here, on demand, via the same AuthenticationManager
 *     the framework `auth` middleware uses (we can't attach `auth` statically because whether auth
 *     is required is per-collection runtime data) — authorized by their Aegis permission.
 *
 * Fail-closed: an unknown collection, a missing route name, or a scoped operation with neither a
 * satisfying scope nor permission all return 403. Method → operation: GET read, POST/PATCH/PUT
 * write, DELETE delete.
 */
final class CollectionScopeMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CollectionDefinitionRepository $collections,
        private readonly AuthenticationManager $auth,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $routeParams = (array) ($request->attributes->get('_route_params') ?? []);
        $name        = (string) ($routeParams['name'] ?? '');
        if ($name === '') {
            return Response::forbidden('Collection name could not be determined');
        }

        $operation  = $this->operation($request->getMethod());
        $capability  = $name . '.' . $operation;

        // Unknown collection → fail-closed (treat as scoped); the controller resolves the 404.
        $definition = $this->collections->findByName($name);
        $level      = $definition?->accessPolicy->forOperation($operation) ?? AccessPolicy::SCOPED;

        if ($level === AccessPolicy::PUBLIC) {
            return $next($request);
        }

        // scoped — api-key path (scopes are the sole authority) vs session path, mutually exclusive.
        if ($request->attributes->has('api_key_scopes')) {
            return $this->apiKeyGrants($request, $capability)
                ? $next($request)
                : Response::forbidden('Insufficient scope: ' . $capability);
        }

        $user = $this->resolveSessionUser($request);
        if ($user !== null && $this->userGrants($user, $capability, $request)) {
            return $next($request);
        }

        return Response::forbidden('This operation requires the capability: ' . $capability);
    }

    /** Map the HTTP method to the access-policy operation. */
    private function operation(string $method): string
    {
        return match (strtoupper($method)) {
            'DELETE' => 'delete',
            'POST', 'PATCH', 'PUT' => 'write',
            default => 'read',
        };
    }

    /** True when the request's api-key scopes satisfy the capability. */
    private function apiKeyGrants(Request $request, string $capability): bool
    {
        /** @var list<string> $granted */
        $granted = array_values(array_filter(
            (array) $request->attributes->get('api_key_scopes', []),
            'is_string',
        ));

        return ApiKeyService::scopeSatisfies($granted, $capability);
    }

    /**
     * Resolve the session user — from a prior middleware's `user` attribute, else by authenticating
     * the request on demand. Returns null for anonymous/invalid; never throws.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSessionUser(Request $request): ?array
    {
        $user = $request->attributes->get('user');
        if (is_array($user) && isset($user['uuid'])) {
            return $user;
        }
        try {
            $authenticated = $this->auth->authenticate($request);

            return is_array($authenticated) && isset($authenticated['uuid']) ? $authenticated : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * True when the session user holds the Aegis permission for the capability.
     *
     * @param array<string, mixed> $user
     */
    private function userGrants(array $user, string $capability, Request $request): bool
    {
        if (!isset($user['uuid']) || !is_string($user['uuid']) || trim($user['uuid']) === '') {
            return false;
        }
        $manager = $this->permissionManager();
        if ($manager === null) {
            return false;
        }
        $roles = isset($user['roles']) && is_array($user['roles'])
            ? array_values(array_filter($user['roles'], 'is_string'))
            : [];
        try {
            return $manager->can(trim($user['uuid']), $capability, 'lemma', [
                'roles' => $roles,
                'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Resolve Aegis's PermissionManager from the container; null when unavailable (fail-closed). */
    private function permissionManager(): ?PermissionManager
    {
        if (!$this->context->hasContainer()) {
            return null;
        }
        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id) && ($manager = $container->get($id)) instanceof PermissionManager) {
                    return $manager;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}
