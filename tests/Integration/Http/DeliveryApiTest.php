<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\DeliveryEtag;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Content\Repositories\VersionRepository;
use App\Tests\Support\FakeLocaleManager;
use App\Tests\Support\LemmaTestCase;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller-level coverage for the published-only delivery API.
 *
 * Drives {@see DeliveryController} directly with the real repositories (same pattern as
 * the admin EntryApiTest), publishing entries through the real {@see PublishService} so
 * the leak-proof read path is exercised end to end.
 */
final class DeliveryApiTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text'],
                ['name' => 'priority', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
            ],
        ]);
    }

    private function controller(LocaleManagerInterface $locales = new FakeLocaleManager()): DeliveryController
    {
        $repo = new DeliveryRepository($this->connection());
        return new DeliveryController(
            $this->appContext(),
            $repo,
            new ContentTypeRepository($this->connection()),
            new FilterCompiler(),
            new SortCompiler(),
            new ReferenceResolver($repo),
            new Projector(),
            new DeliveryEtag(),
            $locales,
        );
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function publishService(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /** Create + save a draft + publish an entry; returns its uuid. */
    private function publish(array $fields): string
    {
        return $this->publishInLocale('en', $fields);
    }

    /** Create + save a draft + publish an entry in a locale; returns its uuid. */
    private function publishInLocale(string $locale, array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, $locale, 1, 'user00000001');
        $entries->saveDraft($uuid, $locale, $fields, 1, 0, 'user00000001');
        $this->publishService()->publish($uuid, $locale, 'user00000001');
        return $uuid;
    }

    /** Create + save a draft but do NOT publish; returns its uuid. */
    private function draftOnly(array $fields): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', $fields, 1, 0, 'user00000001');
        return $uuid;
    }

    private function get(array $query = [], array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        return new Request($query, [], [], [], [], $server);
    }

    public function testIndexReturnsPublishedAndOmitsDraft(): void
    {
        $this->publish(['title' => 'Published one', 'priority' => 5]);
        $this->draftOnly(['title' => 'Draft hidden', 'priority' => 9]);

        $resp = $this->controller()->index($this->get(), 'post');
        self::assertSame(200, $resp->getStatusCode());

        $items = json_decode($resp->getContent(), true)['data']['items'];
        $titles = array_column(array_column($items, 'fields'), 'title');
        self::assertContains('Published one', $titles);
        self::assertNotContains('Draft hidden', $titles);
    }

    public function testIndexUnknownTypeReturns404(): void
    {
        $resp = $this->controller()->index($this->get(), 'nope');
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testShowReturnsPublishedFields(): void
    {
        $uuid = $this->publish(['title' => 'Hello show', 'priority' => 1]);

        $resp = $this->controller()->show($this->get(), 'post', $uuid);
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode($resp->getContent(), true)['data'];
        self::assertSame('Hello show', $data['fields']['title']);
    }

    public function testShowFallsBackThroughI18nLocaleChainByUuid(): void
    {
        $uuid = $this->publishInLocale('en', ['title' => 'English fallback', 'priority' => 1]);

        $resp = $this->controller(new FakeLocaleManager())->show(
            $this->get(['locale' => 'fr']),
            'post',
            $uuid
        );

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true)['data'];
        self::assertSame('en', $data['locale']);
        self::assertSame('English fallback', $data['fields']['title']);
    }

    public function testShowFallsBackThroughI18nLocaleChainByRouteSlug(): void
    {
        $uuid = $this->publishInLocale('en', ['title' => 'English route fallback', 'priority' => 1]);
        $this->connection()->table('entry_routes')->insert([
            'entry_uuid' => $uuid,
            'content_type_uuid' => $this->type,
            'locale' => 'en',
            'slug' => 'hello',
        ]);

        $resp = $this->controller(new FakeLocaleManager())->show(
            $this->get(['locale' => 'fr']),
            'post',
            'hello'
        );

        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode($resp->getContent(), true)['data'];
        self::assertSame('en', $data['locale']);
        self::assertSame('English route fallback', $data['fields']['title']);
    }

    public function testShowUnpublishedReturns404(): void
    {
        $uuid = $this->draftOnly(['title' => 'Not yet']);
        $resp = $this->controller()->show($this->get(), 'post', $uuid);
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testFieldSelectionTrimsOutput(): void
    {
        $this->publish(['title' => 'Trim me', 'body' => 'long body text', 'priority' => 3]);

        $resp = $this->controller()->index($this->get(['fields' => 'title']), 'post');
        $items = json_decode($resp->getContent(), true)['data']['items'];

        self::assertArrayHasKey('title', $items[0]['fields']);
        self::assertArrayNotHasKey('body', $items[0]['fields']);
    }

    public function testFilterByFilterableField(): void
    {
        $this->publish(['title' => 'Low', 'priority' => 1]);
        $this->publish(['title' => 'High', 'priority' => 100]);

        $resp = $this->controller()->index(
            $this->get(['filter' => ['priority' => ['gte' => '50']]]),
            'post'
        );
        self::assertSame(200, $resp->getStatusCode());

        $items = json_decode($resp->getContent(), true)['data']['items'];
        $titles = array_column(array_column($items, 'fields'), 'title');
        self::assertSame(['High'], $titles);
    }

    public function testNonFilterableFilterReturns422(): void
    {
        $this->publish(['title' => 'X', 'priority' => 1]);

        $resp = $this->controller()->index(
            $this->get(['filter' => ['title' => ['eq' => 'X']]]),
            'post'
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testEtagPresentAndMatchingIfNoneMatchReturns304(): void
    {
        $uuid = $this->publish(['title' => 'Etag me', 'priority' => 2]);

        $resp = $this->controller()->show($this->get(), 'post', $uuid);
        $etag = $resp->headers->get('ETag');
        self::assertNotNull($etag);
        // Symfony normalizes Cache-Control directives alphabetically.
        self::assertSame('max-age=60, public', $resp->headers->get('Cache-Control'));

        $cond = $this->controller()->show(
            $this->get([], ['If-None-Match' => $etag]),
            'post',
            $uuid
        );
        self::assertSame(304, $cond->getStatusCode());
        self::assertSame('', (string) $cond->getContent());
    }

    public function testPerContentTypeCacheTtlOverridesGlobalDeliveryTtl(): void
    {
        $this->connection()->table('content_types')
            ->where('uuid', '=', $this->type)
            ->update(['cache_ttl' => 300]);

        $uuid = $this->publish(['title' => 'Custom cache', 'priority' => 2]);

        $resp = $this->controller()->show($this->get(), 'post', $uuid);
        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('max-age=300, public', $resp->headers->get('Cache-Control'));

        $etag = (string) $resp->headers->get('ETag');
        $cond = $this->controller()->show(
            $this->get([], ['If-None-Match' => $etag]),
            'post',
            $uuid
        );

        self::assertSame(304, $cond->getStatusCode());
        self::assertSame('max-age=300, public', $cond->headers->get('Cache-Control'));
    }

    public function testPaginationBranchReturnsPaginatedEnvelope(): void
    {
        $this->publish(['title' => 'P1', 'priority' => 1]);
        $this->publish(['title' => 'P2', 'priority' => 2]);

        $resp = $this->controller()->index($this->get(['page' => '1', 'perPage' => '1']), 'post');
        self::assertSame(200, $resp->getStatusCode());

        $body = json_decode($resp->getContent(), true);
        self::assertSame(2, $body['total']);
        self::assertSame(1, $body['per_page']);
        self::assertCount(1, $body['data']);
    }

    /**
     * Drift guard: cursor-mode list payload matches DeliveryListData; each item
     * matches DeliveryItemData. No page/perPage — exercises the documented DTO shape.
     */
    public function testIndexCursorModeDtoShape(): void
    {
        $this->publish(['title' => 'Drift item', 'priority' => 1]);

        // Cursor mode: no page/perPage query params.
        $resp = $this->controller()->index($this->get(), 'post');
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Delivery\DeliveryListData::class);
        foreach ($data['items'] as $item) {
            self::assertDataMatchesDtoShape($item, \App\Content\Http\DTOs\Responses\Delivery\DeliveryItemData::class);
        }
    }

    /**
     * Drift guard: the single-entry show payload matches DeliveryItemData.
     */
    public function testShowDtoShape(): void
    {
        $uuid = $this->publish(['title' => 'Drift show', 'priority' => 2]);

        $resp = $this->controller()->show($this->get(), 'post', $uuid);
        self::assertSame(200, $resp->getStatusCode());

        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Delivery\DeliveryItemData::class);
    }
}
