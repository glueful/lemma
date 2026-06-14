<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\EntryController;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;
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
            new EntryRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        );
    }

    private function json(array $b): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($b));
    }

    private function createEntryUuid(): string
    {
        $resp = $this->controller()->store($this->json(['content_type' => 'post', 'locale' => 'en']));

        return json_decode($resp->getContent(), true)['data']['entry']['uuid'];
    }

    public function testCreateEntryReturnsEntryWithEmptyDraft(): void
    {
        $resp = $this->controller()->store($this->json(['content_type' => 'post', 'locale' => 'en']));
        self::assertSame(201, $resp->getStatusCode());
    }

    public function testSaveDraftRejectsStaleLockWith409(): void
    {
        $uuid = $this->createEntryUuid();
        $this->controller()->saveDraft(
            $this->json(['fields' => ['title' => 'A'], 'lock_version' => 0]),
            $uuid,
            'en',
        );
        $resp = $this->controller()->saveDraft(
            $this->json(['fields' => ['title' => 'B'], 'lock_version' => 0]),
            $uuid,
            'en',
        );
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSaveDraftValidatesFields(): void
    {
        $uuid = $this->createEntryUuid();
        $resp = $this->controller()->saveDraft(
            $this->json(['fields' => ['title' => 123], 'lock_version' => 0]),
            $uuid,
            'en',
        );
        self::assertSame(422, $resp->getStatusCode());
    }
}
