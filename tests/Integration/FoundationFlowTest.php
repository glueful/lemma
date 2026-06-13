<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\RequireLemmaPermission;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;

/**
 * Proves the LemmaServiceProvider wiring is correct end-to-end through the booted
 * application: the provider's DI definitions resolve, the `lemma_permission` middleware
 * alias resolves, the /v1/admin/* routes are live in the router with their permission
 * middleware attached, and a request driven through the REAL kernel reaches that
 * middleware pipeline (an unauthenticated call to a gated route is rejected, not 404'd —
 * proving routing + the auth/middleware chain are wired, not bypassed).
 *
 * Why wiring-proof and not a full authenticated round-trip: a real bearer-token round
 * trip would need to seed a user, the lemma_admin role with permissions and mint a valid
 * JWT — none of which the harness provides, and inventing those helpers was out of scope
 * for this task (and explicitly discouraged by the plan). The create-type -> create-entry
 * -> save-draft -> publish behavior is already covered by the controller-level tests in
 * Tasks 11-13 (ContentTypeApiTest, EntryApiTest, PublicationApiTest) and the
 * service/repository integration tests. This test's job is the wiring those bypass.
 */
final class FoundationFlowTest extends LemmaTestCase
{
    public function testContainerResolvesLemmaServicesAndControllers(): void
    {
        $c = $this->container();

        self::assertInstanceOf(ContentTypeRepository::class, $c->get(ContentTypeRepository::class));
        self::assertInstanceOf(EntryRepository::class, $c->get(EntryRepository::class));
        self::assertInstanceOf(VersionRepository::class, $c->get(VersionRepository::class));
        self::assertInstanceOf(RouteRepository::class, $c->get(RouteRepository::class));
        self::assertInstanceOf(FieldValidator::class, $c->get(FieldValidator::class));
        self::assertInstanceOf(PublishService::class, $c->get(PublishService::class));
        self::assertInstanceOf(ContentTypeController::class, $c->get(ContentTypeController::class));
        self::assertInstanceOf(EntryController::class, $c->get(EntryController::class));
        self::assertInstanceOf(PublicationController::class, $c->get(PublicationController::class));
    }

    public function testLemmaPermissionMiddlewareAliasResolves(): void
    {
        // This is exactly how Router::resolveMiddleware() turns the string
        // 'lemma_permission:...' into a middleware instance.
        $middleware = $this->container()->get('lemma_permission');

        self::assertInstanceOf(RequireLemmaPermission::class, $middleware);
    }

    /** @return array<int, array{0:string, 1:string, 2:string}> method, path, expected permission middleware */
    public static function adminRoutes(): array
    {
        return [
            ['GET', '/v1/admin/content-types', 'lemma_permission:lemma.entries.read'],
            ['POST', '/v1/admin/content-types', 'lemma_permission:lemma.models.manage'],
            ['GET', '/v1/admin/content-types/{slug}', 'lemma_permission:lemma.entries.read'],
            ['PATCH', '/v1/admin/content-types/{slug}/schema', 'lemma_permission:lemma.models.manage'],
            ['POST', '/v1/admin/entries', 'lemma_permission:lemma.entries.write'],
            ['GET', '/v1/admin/entries/{uuid}', 'lemma_permission:lemma.entries.read'],
            ['GET', '/v1/admin/entries/{uuid}/draft/{locale}', 'lemma_permission:lemma.entries.read'],
            ['PUT', '/v1/admin/entries/{uuid}/draft/{locale}', 'lemma_permission:lemma.entries.write'],
            ['POST', '/v1/admin/entries/{uuid}/publish/{locale}', 'lemma_permission:lemma.entries.publish'],
            ['POST', '/v1/admin/entries/{uuid}/unpublish/{locale}', 'lemma_permission:lemma.entries.publish'],
            ['POST', '/v1/admin/entries/{uuid}/rollback/{locale}', 'lemma_permission:lemma.entries.publish'],
        ];
    }

    /** @dataProvider adminRoutes */
    public function testAdminRoutesAreRegisteredWithAuthAndPermissionMiddleware(
        string $method,
        string $path,
        string $permission
    ): void {
        $route = $this->findRoute($method, $path);

        self::assertNotNull($route, "expected {$method} {$path} to be registered by routes/lemma_admin.php");
        /** @var array<int, string> $middleware */
        $middleware = $route['middleware'];
        self::assertContains('auth', $middleware, "route {$path} must carry the auth middleware");
        self::assertContains($permission, $middleware, "route {$path} must carry {$permission}");
    }

    public function testUnauthenticatedRequestToGatedRouteIsRejectedNotFound(): void
    {
        // A request with no bearer token must hit the auth middleware (live in the
        // pipeline) and be rejected — proving routing + middleware are wired, not that
        // the route is missing (which would be a 404).
        $response = $this->handle($this->jsonRequest('POST', '/v1/admin/content-types', [
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]));

        self::assertSame(401, $response->getStatusCode(), 'expected auth rejection, got: ' . $response->getContent());
    }
}
