<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Search\Index\NullContentReindexer;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves lemma-search is cleanly disable-able: with lemma.search disabled, the boot gate skips
 * loadRoutesFrom() (so /v1/search 404s) and the ContentReindexer factory returns a NullContentReindexer
 * (so the reindex listener no-ops). Dedicated disabled boot with a temp config override, removed in a
 * finally so the shared enabled context other test classes rely on is never poisoned.
 */
final class SearchRemovabilityTest extends LemmaTestCase
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
            "<?php\nreturn ['capabilities' => ['lemma.search' => false]];\n",
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

    public function testSearchRouteAbsentWhenDisabled(): void
    {
        $status = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertSame(404, $status);
    }

    public function testReindexerResolvesToNoOpWhenDisabled(): void
    {
        $reindexer = self::$disabledApp->getContainer()->get(ContentReindexer::class);
        self::assertInstanceOf(NullContentReindexer::class, $reindexer);
    }
}
