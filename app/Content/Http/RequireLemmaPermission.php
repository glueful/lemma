<?php

declare(strict_types=1);

namespace App\Content\Http;

use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Permissions\PermissionManager;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Requires the authenticated user to hold a specific Lemma RBAC permission.
 *
 * Registered under the `lemma_permission` alias and used on the fluent admin routes.
 * The required permission slug is the first middleware parameter; the check runs through
 * the same `PermissionManager::can()` that Aegis backs, scoped to the `lemma` resource.
 *
 * Fails closed: a missing/empty permission parameter, no authenticated identity, an
 * unresolvable PermissionManager, or a denied check all return 403.
 */
final class RequireLemmaPermission implements RouteMiddleware
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        $permission = isset($params[0]) && is_string($params[0]) ? trim($params[0]) : '';
        if ($permission === '') {
            return $this->forbidden();
        }
        $user = $request->attributes->get('auth.user');
        if (!$user instanceof UserIdentity) {
            return $this->forbidden();
        }
        $manager = $this->permissionManager();
        if (!$manager instanceof PermissionManager) {
            return $this->forbidden();
        }
        $context = [
            'roles' => $user->roles(),
            'scopes' => $user->scopes(),
            'jwt_claims' => (array) $request->attributes->get('jwt.claims'),
        ];
        if (!$manager->can($user->id(), $permission, 'lemma', $context)) {
            return $this->forbidden();
        }
        return $next($request);
    }

    private function permissionManager(): ?PermissionManager
    {
        if (!$this->context->hasContainer()) {
            return null;
        }

        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id) && ($m = $container->get($id)) instanceof PermissionManager) {
                    return $m;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    private function forbidden(): Response
    {
        return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
    }
}
