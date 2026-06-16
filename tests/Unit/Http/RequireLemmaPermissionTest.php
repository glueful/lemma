<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Content\Http\RequireLemmaPermission;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed contract for the `lemma_permission` middleware.
 *
 * Every guard that cannot positively establish authorization must return 403. The three
 * pre-`can()` branches are exercised here with a bare {@see ApplicationContext} (no container),
 * which makes {@see ApplicationContext::hasContainer()} false so the PermissionManager never
 * resolves — the same deny path a misconfigured deployment would hit. No DB is required.
 */
final class RequireLemmaPermissionTest extends TestCase
{
    public function testEmptyPermissionParamIsForbidden(): void
    {
        $mw = new RequireLemmaPermission($this->contextWithoutContainer());
        $resp = $mw->handle(new Request(), fn() => new Response(), '');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testNoAuthUserIsForbidden(): void
    {
        $mw = new RequireLemmaPermission($this->contextWithoutContainer());
        // Valid permission param, but no `auth.user` attribute on the request.
        $resp = $mw->handle(new Request(), fn() => new Response(), 'lemma.entries.write');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testUnresolvedPermissionManagerIsForbidden(): void
    {
        $request = new Request();
        $request->attributes->set('auth.user', new \Glueful\Auth\UserIdentity(
            uuid: 'usr_test01',
            roles: ['lemma_admin'],
            username: 'tester',
        ));

        // Valid param + authenticated user, but the context has no container, so the
        // PermissionManager cannot be resolved -> fail closed.
        $mw = new RequireLemmaPermission($this->contextWithoutContainer());
        $resp = $mw->handle($request, fn() => new Response(), 'lemma.entries.write');
        self::assertSame(403, $resp->getStatusCode());
    }

    private function contextWithoutContainer(): ApplicationContext
    {
        // A bare context: hasContainer() is false, so permissionManager() returns null
        // and the middleware fails closed before ever calling can().
        return new ApplicationContext(basePath: \dirname(__DIR__, 3), environment: 'testing');
    }
}
