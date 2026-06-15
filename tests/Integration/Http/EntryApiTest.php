<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\EntryController;
use App\Content\Http\DTOs\AssignRouteData;
use App\Content\Http\DTOs\CreateEntryData;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Symfony\Component\HttpFoundation\Request;

final class EntryApiTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function controller(): EntryController
    {
        return new EntryController(
            $this->appContext(),
            new EntryRepository(
                $this->connection(),
                $this->appContext(),
                new ContentTypeRepository($this->connection()),
            ),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
            new RouteRepository($this->connection()),
            new ReferenceProjectionRepository($this->connection()),
        );
    }

    /**
     * Hydrate a request DTO exactly as the router would, so DTO validation is exercised
     * before the controller sees it. (Lemma DTOs use only built-in rules, so no registry.)
     *
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    private function createEntryUuid(): string
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'en']),
            new Request(),
        );

        return json_decode($resp->getContent(), true)['data']['entry']['uuid'];
    }

    public function testCreateEntryReturnsEntryWithEmptyDraft(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'en']),
            new Request(),
        );
        self::assertSame(201, $resp->getStatusCode());
    }

    public function testStoreResponseMatchesDtoShape(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateEntryData::class, ['content_type' => 'post', 'locale' => 'en']),
            new Request(),
        );
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryCreateResultData::class);
        self::assertDataMatchesDtoShape($data['entry'], \App\Content\Http\DTOs\Responses\Entries\EntryData::class);
        self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
    }

    public function testShowResponseMatchesDtoShape(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->show(new Request(), $uuid);
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\EntryResultData::class);
        self::assertDataMatchesDtoShape($data['entry'], \App\Content\Http\DTOs\Responses\Entries\EntryData::class);
    }

    public function testGetDraftResponseMatchesDtoShape(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->getDraft(new Request(), $uuid, 'en');
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\DraftResultData::class);
        self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
    }

    public function testSaveDraftResponseMatchesDtoShape(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'Hello'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(200, $resp->getStatusCode());
        $data = json_decode((string) $resp->getContent(), true)['data'];
        self::assertDataMatchesDtoShape($data, \App\Content\Http\DTOs\Responses\Entries\DraftResultData::class);
        self::assertDataMatchesDtoShape($data['draft'], \App\Content\Http\DTOs\Responses\Entries\DraftData::class);
    }

    public function testSaveDraftRejectsStaleLockWith409(): void
    {
        $uuid = $this->createEntryUuid();
        $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'A'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        $resp = $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 'B'], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSaveDraftValidatesFields(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->saveDraft(
            $this->hydrate(SaveDraftData::class, ['fields' => ['title' => 123], 'lock_version' => 0]),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testDiscardDraftDeletesWorkingCopy(): void
    {
        $uuid = $this->createEntryUuid();

        $resp = $this->controller()->discardDraft(new Request(), $uuid, 'en');

        self::assertSame(200, $resp->getStatusCode());
        self::assertNull((new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        ))->findDraft($uuid, 'en'));
    }

    public function testDestroySoftDeletesEntryAndBlocksReferencedDeletes(): void
    {
        $target = $this->createEntryUuid();
        $source = $this->createEntryUuid();
        $projection = new ReferenceProjectionRepository($this->connection());
        $projection->rebuildForEntry(
            $source,
            ContentTypeSchema::fromArray([['name' => 'related', 'type' => 'reference']]),
            ['related' => $target],
        );

        $blocked = $this->controller()->destroy(new Request(), $target);
        self::assertSame(409, $blocked->getStatusCode());

        $ok = $this->controller()->destroy(new Request(), $source);
        self::assertSame(200, $ok->getStatusCode());
        self::assertSame('deleted', $this->controllerEntryRepo()->findEntry($source)['status']);
    }

    public function testAssignListAndRemoveRoute(): void
    {
        $uuid = $this->createEntryUuid();

        $assign = $this->controller()->assignRoute(
            $this->hydrate(AssignRouteData::class, ['slug' => 'hello-world']),
            new Request(),
            $uuid,
            'en',
        );
        self::assertSame(200, $assign->getStatusCode());

        $routes = $this->controller()->routes(new Request(), $uuid);
        $data = json_decode((string) $routes->getContent(), true)['data'];
        self::assertSame('hello-world', $data['routes'][0]['slug']);

        $remove = $this->controller()->removeRoute(new Request(), $uuid, 'en');
        self::assertSame(200, $remove->getStatusCode());
        $afterRemove = json_decode(
            (string) $this->controller()->routes(new Request(), $uuid)->getContent(),
            true
        );
        self::assertSame([], $afterRemove['data']['routes']);
    }

    private function controllerEntryRepo(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }
}
