<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Removability contract: with lemma.analytics DISABLED at boot, the admin route surface is entirely
 * absent — GET /v1/admin/analytics/summary returns 404 (route unregistered), not 401 from a
 * live-but-disabled auth gate.
 */
final class AnalyticsRemovabilityTest extends LemmaTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass(); // shared ENABLED app

        if (self::$disabledApp !== null) {
            return;
        }

        $root = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        $overrideFile = $overrideDir . '/lemma.php';

        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents(
            $overrideFile,
            "<?php\nreturn ['capabilities' => ['lemma.analytics' => false]];\n",
        );

        RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        try {
            self::$disabledApp = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        } finally {
            @unlink($overrideFile);
            if (is_dir($overrideDir) && count((array) scandir($overrideDir)) === 2) {
                @rmdir($overrideDir);
            }
        }

        RouteManifest::reset();
    }

    public function testDisabledBootAnalyticsRouteReturns404(): void
    {
        $response = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/admin/analytics/summary', 'GET', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ]),
        );

        self::assertSame(
            404,
            $response->getStatusCode(),
            'Disabled-boot GET /v1/admin/analytics/summary must be 404 (route unregistered), got: '
            . $response->getStatusCode() . ' body: ' . $response->getContent()
        );
    }
}
