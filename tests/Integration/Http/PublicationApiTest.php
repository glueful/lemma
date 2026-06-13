<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\PublicationController;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
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
        $entries = new EntryRepository($this->connection());
        $this->entry = $entries->createEntry($type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'Hello'], 1, 0, 'user00000001');
    }

    private function controller(): PublicationController
    {
        return new PublicationController(new PublishService(
            $this->appContext(),
            new EntryRepository($this->connection()),
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        ));
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
}
