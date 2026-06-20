<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\AdminConfigController;
use App\Tests\Support\LemmaTestCase;

final class AdminConfigApiTest extends LemmaTestCase
{
    public function testReturnsRuntimeConfigKeys(): void
    {
        $controller = new AdminConfigController($this->appContext());
        $resp = $controller->config();

        self::assertSame(200, $resp->getStatusCode());
        $body = json_decode((string) $resp->getContent(), true);
        self::assertArrayHasKey('apiBase', $body);
        self::assertArrayHasKey('sitePreviewUrl', $body);
        self::assertArrayHasKey('defaultLocale', $body);
        self::assertSame('/v1/admin', $body['apiBase']);
    }

    public function testConfigRouteIsRegisteredUnauthenticated(): void
    {
        // The SPA needs apiBase BEFORE it can log in, so this route must NOT be in the
        // /v1/admin auth group. Assert it is registered and carries no `auth` middleware.
        $route = $this->findRoute('GET', '/admin/config.json');
        self::assertNotNull($route, '/admin/config.json must be registered');
        $middleware = (array) ($route['middleware'] ?? []);
        self::assertNotContains('auth', $middleware, 'config.json must be unauthenticated');
    }
}
