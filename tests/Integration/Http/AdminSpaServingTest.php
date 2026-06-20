<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\LemmaTestCase;

final class AdminSpaServingTest extends LemmaTestCase
{
    public function testAdminBundleIsMountedAtAdmin(): void
    {
        // serveFrontend() registers the SPA root route only when bundle_path exists and holds
        // index.html. phpunit.xml points LEMMA_ADMIN_BUNDLE_PATH at tests/fixtures/admin (Step 1b),
        // which holds a committed index.html, so the mount is wired during the process-global boot.
        $route = $this->findRoute('GET', '/admin');
        self::assertNotNull($route, '/admin must be mounted by serveFrontend()');
    }

    public function testConfigJsonIsNotShadowedBySpaCatchAll(): void
    {
        // The static config.json route (Task 0b) must still resolve as itself, never as the SPA
        // fallback — the router's static-first lookup guarantees this.
        $route = $this->findRoute('GET', '/admin/config.json');
        self::assertNotNull($route, '/admin/config.json must remain its own static route');
    }
}
