<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Content\Http\RequireContentScope;
use Glueful\Http\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Fail-closed contract for the `require_content_scope` middleware.
 *
 * Unlike the framework's attribute-only RequireScopeMiddleware (which fails OPEN for
 * fluent file routes — it reads the route's scope config, ignores ...$params, and falls
 * through to $next), this middleware reads the required scope from its first parameter
 * and DENIES whenever it cannot positively establish the scope. These tests pin that
 * every pre-grant branch returns 403 and only a satisfied scope reaches $next.
 */
final class RequireContentScopeTest extends TestCase
{
    public function testEmptyScopeParamIsForbidden(): void
    {
        $mw = new RequireContentScope();
        $resp = $mw->handle(new Request(), fn() => new Response(), '');
        self::assertInstanceOf(Response::class, $resp);
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testMissingApiKeyScopesAttributeIsForbidden(): void
    {
        // No `api_key_scopes` attribute at all -> the request was not authenticated via
        // a scoped API key -> fail closed.
        $mw = new RequireContentScope();
        $resp = $mw->handle(new Request(), fn() => new Response(), 'read:content');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testInsufficientScopeIsForbidden(): void
    {
        $request = new Request();
        $request->attributes->set('api_key_scopes', ['write:content']);

        $mw = new RequireContentScope();
        $resp = $mw->handle($request, fn() => new Response(), 'read:content');
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testSatisfiedScopeCallsNext(): void
    {
        $request = new Request();
        $request->attributes->set('api_key_scopes', ['read:content']);

        $called = false;
        $mw = new RequireContentScope();
        $resp = $mw->handle($request, function () use (&$called) {
            $called = true;
            return new Response(['ok' => true]);
        }, 'read:content');

        self::assertTrue($called, 'satisfied scope must reach $next');
        self::assertSame(200, $resp->getStatusCode());
    }
}
