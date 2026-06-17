<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

abstract class LemmaTestCase extends TestCase
{
    protected static ?ApplicationContext $app = null;

    // Truncate order is child -> parent (no FKs in v1, but keep it deterministic).
    private const TABLES = [
        'entry_schedules',
        'import_export_reports', 'import_export_errors', 'import_export_files',
        'import_export_batches', 'import_export_jobs',
        'entry_schema_migrations', 'entry_references', 'entry_redirects', 'entry_routes', 'entry_publications',
        'entry_versions', 'entry_drafts', 'entries', 'content_types',
    ];

    public static function setUpBeforeClass(): void
    {
        if (self::$app === null) {
            $root = dirname(__DIR__, 2);

            // Earlier suites (e.g. tests/Feature, which boot the framework per test)
            // leave RouteManifest::$loaded === true process-globally. Without resetting
            // it, this boot's RouteManifest::load() early-returns and the app routes
            // (including routes/lemma_admin.php) never register in THIS router. The
            // framework's per-boot route cache would normally paper over that, but its
            // signature is basePath-sensitive and is discarded across differing boots,
            // leaving an empty router. Reset the manifest and drop any stale route cache
            // so this boot loads every routes/*.php file fresh and deterministically.
            RouteManifest::reset();
            self::clearRouteCache($root);

            // Schema is created by `composer test:migrate` before PHPUnit runs.
            // Framework::boot() returns a Glueful\Application; we keep its
            // ApplicationContext (both expose getContainer()).
            self::$app = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        }
    }

    private static function clearRouteCache(string $root): void
    {
        foreach (glob($root . '/storage/cache/routes_*.php') ?: [] as $file) {
            @unlink($file);
        }
    }

    /** Verified once per process: are the tables actually migrated? */
    private static bool $schemaVerified = false;

    protected function setUp(): void
    {
        // Fail loud and clear if the test DB isn't migrated, instead of letting every
        // test trip over a raw "relation ... does not exist" on the first truncate
        // (which masks the real cause — e.g. the migration bootstrap dying on a
        // ConnectionPoolException). Checked once per process.
        if (!self::$schemaVerified) {
            $schema = $this->connection()->getSchemaBuilder();
            foreach (self::TABLES as $t) {
                if (!$schema->hasTable($t)) {
                    self::fail(
                        "Test database is not migrated: table '{$t}' is missing. "
                        . "Run `composer test:migrate`. In CI, check the migration step for a "
                        . "ConnectionPoolException (the pool must be off: DB_POOLING_ENABLED=false)."
                    );
                }
            }
            self::$schemaVerified = true;
        }

        // QueryBuilder has no truncate(); delete-all via a tautological predicate
        // (every Lemma table has an integer `id`). Deletes commit immediately.
        foreach (self::TABLES as $t) {
            $this->connection()->table($t)->where('id', '>', 0)->delete();
        }
    }

    protected function appContext(): ApplicationContext
    {
        return self::$app;
    }

    protected function connection(): Connection
    {
        return $this->container()->get(Connection::class);
    }

    protected function container(): ContainerInterface
    {
        return self::$app->getContainer();
    }

    protected function router(): Router
    {
        return $this->container()->get(Router::class);
    }

    /**
     * Drive a request through the real application kernel (Router::dispatch via
     * Application::handle) — the same entry point public/index.php uses.
     */
    protected function handle(Request $request): HttpResponse
    {
        return (new Application(self::$app))->handle($request);
    }

    /** Build a JSON request with method, path and (optional) body. */
    protected function jsonRequest(string $method, string $path, ?array $body = null): Request
    {
        return Request::create(
            $path,
            $method,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $body === null ? null : (string) json_encode($body)
        );
    }

    /**
     * Find a registered route by method + exact path. Returns the Router's route
     * descriptor (handler, middleware, name, ...) or null if no such route exists.
     *
     * @return array<string, mixed>|null
     */
    protected function findRoute(string $method, string $path): ?array
    {
        foreach ($this->router()->getAllRoutes() as $route) {
            if (
                strtoupper((string) $route['method']) === strtoupper($method)
                && (string) $route['path'] === $path
            ) {
                return $route;
            }
        }
        return null;
    }

    /**
     * Assert a runtime `data` payload's keys match a doc-only ResponseData DTO's
     * constructor params. With $exact=false, the payload keys must be a SUBSET of the
     * DTO params (for shapes that omit falsy keys, e.g. ContentTypeSchema::toArray()).
     * Never recurses into freeform `fields`.
     *
     * @param array<string,mixed>           $data
     * @param class-string<\Glueful\Http\Contracts\ResponseData> $dtoClass
     */
    protected static function assertDataMatchesDtoShape(array $data, string $dtoClass, bool $exact = true): void
    {
        $params = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod($dtoClass, '__construct'))->getParameters()
        );
        $actual = array_keys($data);
        if ($exact) {
            sort($params);
            sort($actual);
            self::assertSame($params, $actual, "Payload keys differ from {$dtoClass}");
        } else {
            self::assertSame([], array_diff($actual, $params), "Payload has keys not in {$dtoClass}");
        }
    }
}
