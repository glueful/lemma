<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use App\Tests\Support\LemmaTestCase;

final class FieldValidatorMediaDiskTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteBlobFixtures();
    }

    protected function tearDown(): void
    {
        $this->deleteBlobFixtures();
        $this->setConfig('lemma.media_disk', 'local');
        parent::tearDown();
    }

    public function testAssetFieldRequiresActiveBlobOnConfiguredMediaDisk(): void
    {
        $this->setConfig('lemma.media_disk', 'media');
        $this->insertBlob('assetmedia01', 'media');
        $this->insertBlob('assetlocal01', 'local');

        $validator = $this->container()->get(FieldValidator::class);
        self::assertInstanceOf(FieldValidator::class, $validator);

        $clean = $validator->validate($this->assetSchema(), ['hero' => 'assetmedia01']);
        self::assertSame(['hero' => 'assetmedia01'], $clean);

        try {
            $validator->validate($this->assetSchema(), ['hero' => 'assetlocal01']);
            self::fail('Expected asset on the wrong media disk to fail validation.');
        } catch (ValidationException $e) {
            self::assertSame(
                ['hero' => 'must reference an active blob on the configured media disk'],
                $e->errors()
            );
        }
    }

    private function assetSchema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'hero', 'type' => 'asset', 'required' => true],
        ]);
    }

    private function insertBlob(string $uuid, string $disk): void
    {
        $this->connection()->table('blobs')->insert([
            'uuid' => $uuid,
            'name' => $uuid . '.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 123,
            'url' => '/uploads/' . $uuid . '.jpg',
            'storage_type' => $disk,
            'visibility' => 'private',
            'status' => 'active',
            'created_by' => 'user00000001',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function deleteBlobFixtures(): void
    {
        $stmt = $this->connection()->getPDO()->prepare(
            "DELETE FROM blobs WHERE uuid IN ('assetmedia01', 'assetlocal01')"
        );
        $stmt->execute();
    }

    private function setConfig(string $key, mixed $value): void
    {
        $prop = (new \ReflectionClass($this->appContext()))->getProperty('configCache');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $cache */
        $cache = $prop->getValue($this->appContext());
        $cache[$key] = $value;
        $prop->setValue($this->appContext(), $cache);
    }
}
