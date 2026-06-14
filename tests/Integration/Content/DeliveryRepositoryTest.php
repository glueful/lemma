<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;

final class DeliveryRepositoryTest extends LemmaTestCase
{
    private string $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->type = (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'post',
            'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function publish(string $title, string $slug): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'user00000001');
        (new RouteRepository($this->connection()))->assign($uuid, $this->type, 'en', $slug);
        (new PublishService(
            $this->appContext(),
            $entries,
            new VersionRepository($this->connection()),
            new ContentTypeRepository($this->connection()),
            new FieldValidator()
        ))->publish($uuid, 'en', 'user00000001');
        return $uuid;
    }

    private function repo(): DeliveryRepository
    {
        return new DeliveryRepository($this->connection());
    }

    public function testListReturnsOnlyPublished(): void
    {
        $this->publish('Live', 'live');
        // an unpublished entry (draft saved, never published)
        $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');

        $rows = $this->repo()->listPublished($this->type, 'en', limit: 20);

        self::assertCount(1, $rows);
        self::assertSame('Live', $rows[0]['fields']['title']);
    }

    public function testFindBySlugReturnsPublishedVersion(): void
    {
        $this->publish('Hello', 'hello');

        $row = $this->repo()->findPublishedByRoute($this->type, 'en', 'hello');

        self::assertNotNull($row);
        self::assertSame('Hello', $row['fields']['title']);
        self::assertArrayHasKey('version_uuid', $row);
        self::assertNull($this->repo()->findPublishedByRoute($this->type, 'en', 'does-not-exist'));
    }

    public function testFindByUuidReturnsOnlyPublished(): void
    {
        $published = $this->publish('Visible', 'visible');
        $draftOnly = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');

        $found = $this->repo()->findPublishedByUuid($this->type, 'en', $published);
        self::assertNotNull($found);
        self::assertSame('Visible', $found['fields']['title']);
        self::assertNull($this->repo()->findPublishedByUuid($this->type, 'en', $draftOnly));
    }

    public function testPublishedByEntryUuidsBatchExcludesUnpublished(): void
    {
        $published = $this->publish('Batch', 'batch');
        $draftOnly = $this->entries()->createEntry($this->type, 'en', 1, 'user00000001');

        $out = $this->repo()->publishedByEntryUuids([$published, $draftOnly], 'en');

        self::assertArrayHasKey($published, $out);
        self::assertArrayNotHasKey($draftOnly, $out);
        self::assertSame('Batch', $out[$published]['fields']['title']);
    }
}
