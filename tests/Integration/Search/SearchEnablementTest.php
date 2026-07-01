<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Search\Index\ResilientContentReindexer;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

/**
 * lemma-search is OPT-IN — absent from the default config/extensions.php allow-list (a lean
 * install binds no reindexer and does not register /v1/search; see CapabilityGatingTest +
 * SearchEndpointTest::testRouteAbsentByDefaultBecausePackIsOptIn). This proves the other
 * direction: once the provider is enabled, the boot gate registers /v1/search and binds the
 * (resilient) ContentReindexer. Dedicated enabled boot via a temp config/testing/extensions.php
 * override (the real allow-list + this provider), removed in a finally so the shared context is
 * never poisoned. Mirrors SeoRemovabilityTest's override strategy.
 */
final class SearchEnablementTest extends LemmaTestCase
{
    private static ?ApplicationContext $enabledApp = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$enabledApp !== null) {
            return;
        }

        $root = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        $overrideFile = $overrideDir . '/extensions.php';

        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }

        // The real allow-list plus lemma-search (opt-in, so not in the default list).
        /** @var array{enabled: list<string>} $base */
        $base = require $root . '/config/extensions.php';
        $enabled = $base['enabled'];
        $enabled[] = 'Glueful\\Lemma\\Search\\LemmaSearchServiceProvider';
        file_put_contents(
            $overrideFile,
            "<?php\nreturn ['enabled' => " . var_export($enabled, true) . "];\n",
        );

        RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        try {
            self::$enabledApp = Framework::create($root)
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

    public function testRouteRegisteredWhenEnabled(): void
    {
        // With Meilisearch unreachable in tests the handler fails closed (503); a running server
        // would return 200. Either way it is NOT 404 — the route is registered.
        $status = (new Application(self::$enabledApp))->handle(
            Request::create('/v1/search?q=x&locale=en', 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']),
        )->getStatusCode();
        self::assertNotSame(404, $status, 'enabling lemma-search must register /v1/search');
    }

    public function testReindexerBoundToResilientWhenEnabled(): void
    {
        $reindexer = self::$enabledApp->getContainer()->get(ContentReindexer::class);
        self::assertInstanceOf(ResilientContentReindexer::class, $reindexer);
    }
}
