<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Framework;
use Glueful\Lemma\Collections\CollectionManager;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Lemma\Contracts\Authoring\ContentWriter;
use Glueful\Lemma\Contracts\Context\LemmaContext;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;

/**
 * Proves that lemma-collections is cleanly disable-able ("removable") at runtime.
 *
 * Three properties are asserted via a dedicated disabled-capability boot:
 *
 *   1. Route surface gone: GET /v1/collections/{name} returns 404 (not 403 from a
 *      live-but-disabled handler) because the Task-2 boot gate skips loadRoutesFrom()
 *      entirely when lemma.collections is disabled.
 *
 *   2. Data preserved: collection_definitions and any materialized collection_* data
 *      table survive the disable — the pack migrations run on INSTALL, not on enable/
 *      disable, so disabling never drops a table.
 *
 *   3. Core unbroken: the content-engine services (ContentWriter, ContentDeliveryReader,
 *      LemmaContext, FieldTypeRegistry) still resolve from the disabled-boot container,
 *      proving the pack has no required footprint in core.
 *
 * Boot strategy: we need two contexts:
 *   - The SHARED enabled boot (LemmaTestCase::$app) — used in setUp to create a real
 *     collection so the data-persistence assertions are non-trivial.
 *   - A DEDICATED disabled boot ($disabledApp, see setUpBeforeClass) — booted with a
 *     temporary config/testing/lemma.php that sets capabilities.lemma.collections=false,
 *     which the DefaultCapabilityRegistry factory reads before LemmaCollectionsServiceProvider
 *     registers routes. After the second boot the override file is deleted and RouteManifest
 *     is reset so subsequent test classes re-use the shared enabled context unaffected.
 */
final class RemovabilityTest extends LemmaTestCase
{
    /** Boot-level disabled context: fresh Framework boot with lemma.collections=false. */
    private static ?ApplicationContext $disabledApp = null;

    /** Name of the collection created during setUp for persistence assertions. */
    private const COL = 'removability_proof';

    /** CollectionDefinition created in setUp (carries the derived tableName). */
    private ?CollectionDefinition $def = null;

    // ── Boot ─────────────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        // Boot (or reuse) the shared ENABLED app.
        parent::setUpBeforeClass();

        if (self::$disabledApp !== null) {
            return;
        }

        $root        = dirname(__DIR__, 3);
        $overrideDir = $root . '/config/testing';
        $overrideFile = $overrideDir . '/lemma.php';

        // Create the env-specific config override that disables lemma.collections.
        // ConfigurationLoader::loadEnvironmentConfig() merges $configPath/$env/lemma.php
        // on top of config/lemma.php via array_replace_recursive, so returning only the
        // capabilities key is enough to shadow that subtree.
        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }
        file_put_contents(
            $overrideFile,
            "<?php\nreturn ['capabilities' => ['lemma.collections' => false]];\n",
        );

        // Reset process-global route state so the second boot starts clean.
        RouteManifest::reset();
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $f) {
            @unlink($f);
        }

        // Boot the disabled app. ConfigurationLoader picks up the testing/lemma.php
        // override; DefaultCapabilityRegistry is constructed with lemma.collections=>false;
        // LemmaCollectionsServiceProvider::boot() skips loadRoutesFrom().
        try {
            self::$disabledApp = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        } finally {
            // Always remove the override — even if the boot throws — otherwise the next test
            // class's shared enabled boot would pick up lemma.collections=false and collapse
            // the whole suite with mysterious route/service failures.
            @unlink($overrideFile);
            if (is_dir($overrideDir) && count((array) scandir($overrideDir)) === 2) {
                @rmdir($overrideDir);
            }
        }

        // Reset the manifest again: subsequent test classes whose setUpBeforeClass calls
        // RouteManifest::reset() will find it already clean, and since LemmaTestCase::$app
        // is non-null they skip re-booting — so they keep the shared enabled context.
        RouteManifest::reset();
    }

    // ── Per-test setup ────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp(); // standard table truncation via shared enabled app

        $this->cleanupCollection(); // idempotent: drop any leftover from a previous run

        // Create a real collection via the shared ENABLED app so the persistence
        // assertions have an actual table to verify (not just collection_definitions).
        $this->def = $this->manager()->create(
            [
                'name'   => self::COL,
                'label'  => 'Removability Proof',
                'fields' => [
                    ['name' => 'title', 'type' => 'collections.text', 'settings' => ['nullable' => false]],
                ],
            ],
            'system',
            'test',
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupCollection();
        parent::tearDown();
    }

    // ── Tests ──────────────────────────────────────────────────────────────────

    /**
     * The public route surface must be entirely absent in a disabled-capability boot.
     * The router never registers /v1/collections/{name}, so the response is 404 (not
     * 403 from a live-but-disabled handler — that would mean the route IS registered).
     */
    public function testDisabledBootCollectionsRouteReturns404(): void
    {
        $response = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/collections/' . self::COL, 'GET', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ]),
        );

        self::assertSame(
            404,
            $response->getStatusCode(),
            'Disabled-boot GET /v1/collections/{name} must return 404 (route unregistered), '
                . 'got: ' . $response->getStatusCode() . ' body: ' . $response->getContent(),
        );
    }

    /**
     * The admin route surface is loaded inside the same capability gate, so it is equally absent
     * when disabled — GET /v1/admin/collections returns 404, not 401 from a live-but-disabled auth gate.
     */
    public function testDisabledBootAdminCollectionsRouteReturns404(): void
    {
        $response = (new Application(self::$disabledApp))->handle(
            Request::create('/v1/admin/collections', 'GET', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
            ]),
        );

        self::assertSame(
            404,
            $response->getStatusCode(),
            'Disabled-boot GET /v1/admin/collections must return 404 (admin route unregistered), '
                . 'got: ' . $response->getStatusCode(),
        );
    }

    /**
     * The collection_definitions metadata table must exist in the disabled boot.
     * The provider registers migrations outside the isEnabled gate (spec §5 / Task 2),
     * so disabling the capability never drops the schema.
     */
    public function testCollectionDefinitionsTablePersistsWhenDisabled(): void
    {
        $schema = self::$disabledApp->getContainer()->get(SchemaBuilderInterface::class);
        $schema->reset(); // invalidate any schema-builder cache

        self::assertTrue(
            $schema->hasTable('collection_definitions'),
            'collection_definitions must exist in the disabled boot',
        );
    }

    /**
     * A collection_* data table materialized while the capability was enabled must
     * survive the disable. The setUp creates a collection (and its physical table) via
     * the enabled boot; this test verifies the same table is accessible from the
     * disabled-boot schema builder — proving disable never issues DROP TABLE.
     */
    public function testMaterializedDataTablePersistsWhenDisabled(): void
    {
        self::assertNotNull($this->def, 'setUp must have created a collection');

        $schema = self::$disabledApp->getContainer()->get(SchemaBuilderInterface::class);
        $schema->reset();

        self::assertTrue(
            $schema->hasTable($this->def->tableName),
            "Materialized table {$this->def->tableName} must survive a disabled-capability boot",
        );
    }

    /**
     * The core content-engine contract bindings must resolve from the disabled-boot
     * container. The lemma-collections pack being off must not break core resolution.
     */
    public function testContentEngineServicesResolveWithCollectionsDisabled(): void
    {
        $container = self::$disabledApp->getContainer();

        self::assertInstanceOf(
            ContentWriter::class,
            $container->get(ContentWriter::class),
            'ContentWriter must resolve from disabled-boot container',
        );

        self::assertInstanceOf(
            ContentDeliveryReader::class,
            $container->get(ContentDeliveryReader::class),
            'ContentDeliveryReader must resolve from disabled-boot container',
        );

        self::assertInstanceOf(
            LemmaContext::class,
            $container->get(LemmaContext::class),
            'LemmaContext must resolve from disabled-boot container',
        );

        self::assertInstanceOf(
            FieldTypeRegistry::class,
            $container->get(FieldTypeRegistry::class),
            'FieldTypeRegistry must resolve from disabled-boot container',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function cleanupCollection(): void
    {
        $schema = $this->container()->get(SchemaBuilderInterface::class);
        $schema->reset();

        $tableName = 'collection_' . substr(hash('sha256', self::COL), 0, 12);
        if ($schema->hasTable($tableName)) {
            $schema->dropTableIfExists($tableName);
        }

        $this->connection()->table('collection_definitions')
            ->where('name', self::COL)
            ->delete();

        $this->connection()->table('collection_schema_changes')
            ->where('id', '>', 0)
            ->delete();
    }
}
