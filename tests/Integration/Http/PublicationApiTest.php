<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\DTOs\RollbackData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class PublicationApiTest extends LemmaTestCase
{
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();
        $type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->entries();
        $this->entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'Hello'], 1, 0, 'user00000001');
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function controller(): PublicationController
    {
        return new PublicationController(new PublishService(
            $this->appContext(),
            $this->entries(),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        ));
    }

    /**
     * Hydrate a request DTO exactly as the router would, so DTO validation is exercised
     * before the controller sees it.
     *
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    public function testPublishReturns200AndPins(): void
    {
        $resp = $this->controller()->publish(new Request(), $this->entry, 'en');
        self::assertSame(200, $resp->getStatusCode());
        self::assertNotNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }

    public function testUnpublishRemovesPin(): void
    {
        $this->controller()->publish(new Request(), $this->entry, 'en');
        $this->controller()->unpublish(new Request(), $this->entry, 'en');
        self::assertNull((new VersionRepository($this->connection()))->findPublication($this->entry, 'en'));
    }

    public function testRollbackReturns200ForOwnedVersion(): void
    {
        $publishResp = $this->controller()->publish(new Request(), $this->entry, 'en');
        $versionUuid = json_decode($publishResp->getContent(), true)['data']['version_uuid'];

        $resp = $this->controller()->rollback(
            $this->hydrate(RollbackData::class, ['version_uuid' => $versionUuid]),
            new Request(),
            $this->entry,
            'en',
        );
        self::assertSame(200, $resp->getStatusCode());
    }

    public function testRollbackRejectsMissingVersionAtHydration(): void
    {
        // Structural validation now lives in the DTO: a blank/absent version_uuid fails
        // hydration with a standard 422 before the controller runs.
        $this->expectException(ValidationException::class);
        $this->hydrate(RollbackData::class, []);
    }

    public function testRollbackRejectsUnknownVersionWith422(): void
    {
        // version_uuid is structurally valid but does not belong to this entry+locale —
        // the service raises a RuntimeException the controller maps to a domain 422.
        $resp = $this->controller()->rollback(
            $this->hydrate(RollbackData::class, ['version_uuid' => 'does-not-exist']),
            new Request(),
            $this->entry,
            'en',
        );
        self::assertSame(422, $resp->getStatusCode());
    }
}
