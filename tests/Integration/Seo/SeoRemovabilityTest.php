<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves lemma-seo is cleanly disable-able: with lemma.seo disabled, the Task-4/5/6 boot
 * gate skips loadRoutesFrom() entirely, so every SEO surface (public meta, sitemap, robots,
 * admin meta) returns 404 — route unregistered, not a live-but-disabled handler.
 *
 * Boot strategy mirrors Collections\RemovabilityTest: a dedicated disabled boot with a
 * temporary config/testing/lemma.php override, removed in a finally so the shared enabled
 * context other test classes rely on is never poisoned.
 */
final class SeoRemovabilityTest extends LemmaTestCase
{
    private static ?ApplicationContext $disabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

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
            "<?php\nreturn ['capabilities' => ['lemma.seo' => false]];\n",
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

    private function hit(string $method, string $path): int
    {
        return (new Application(self::$disabledApp))->handle(
            Request::create($path, $method, [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ]),
        )->getStatusCode();
    }

    public function testPublicMetaRouteAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/v1/seo/meta/blog/hello'));
    }

    public function testSitemapRoutesAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/sitemap.xml'));
        self::assertSame(404, $this->hit('GET', '/sitemap/1.xml'));
    }

    public function testRobotsRouteAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/robots.txt'));
    }

    public function testAdminMetaRouteAbsentWhenDisabled(): void
    {
        self::assertSame(404, $this->hit('GET', '/v1/admin/seo/meta/e-1'));
    }
}
