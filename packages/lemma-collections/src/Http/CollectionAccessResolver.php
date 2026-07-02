<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Http;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Auth\AuthenticationManager;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Lemma\Collections\Schema\AccessPolicy;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;

/**
 * The per-collection, per-operation access decision — the single source of truth shared by
 * {@see CollectionScopeMiddleware} (which gates the URL collection) and
 * {@see \Glueful\Lemma\Collections\Relations\RelationResolver} (which must gate the TARGET of
 * an `?expand`, so reading a public/low-scoped collection can't pull a scoped collection's rows).
 *
 * An operation is allowed when the collection's policy makes it public, OR the request's api-key
 * scopes satisfy the `{name}.{operation}` capability, OR (no api-key) a session user holds the
 * matching Aegis permission. api-key and session are mutually exclusive: a key's scopes are its
 * sole authority. Fail-closed everywhere.
 */
final class CollectionAccessResolver
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AuthenticationManager $auth,
    ) {
    }

    /**
     * True when $request may perform $operation (read|write|delete) on the collection $def (named
     * $name). A null $def is treated as scoped (fail-closed) — the caller resolves the 404.
     */
    public function allows(Request $request, ?CollectionDefinition $def, string $name, string $operation): bool
    {
        $level = $def?->accessPolicy->forOperation($operation) ?? AccessPolicy::SCOPED;
        if ($level === AccessPolicy::PUBLIC) {
            return true;
        }

        $capability = $name . '.' . $operation;

        // scoped — api-key path (scopes are the sole authority) vs session path, mutually exclusive.
        if ($request->attributes->has('api_key_scopes')) {
            return $this->apiKeyGrants($request, $capability);
        }

        $user = $this->resolveSessionUser($request);
        return $user !== null && $this->userGrants($user, $capability, $request);
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
