<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewNotFoundException;
use App\Content\Preview\PreviewReader;
use App\Content\Preview\PreviewToken;
use App\Content\Preview\PreviewTokenException;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\Tests\Support\LemmaTestCase;

final class PreviewApiTest extends LemmaTestCase
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

    private function entries(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->connection());
    }

    private function minter(): PreviewMinter
    {
        return new PreviewMinter($this->appContext());
    }

    private function reader(): PreviewReader
    {
        return new PreviewReader($this->appContext(), $this->entries(), $this->versions());
    }

    /** Seed an entry + a draft carrying the given title; return the entry uuid. */
    private function seedDraft(string $title): string
    {
        $entries = $this->entries();
        $uuid = $entries->createEntry($this->type, 'en', 1, 'tester');
        $entries->saveDraft($uuid, 'en', ['title' => $title], 1, 0, 'tester');
        return $uuid;
    }

    public function testMintReturnsTokenString(): void
    {
        $uuid = $this->seedDraft('Draft Title');
        $token = $this->minter()->mint($uuid, 'en', null);
        self::assertIsString($token);
        self::assertStringContainsString('.', $token);
    }

    public function testReadResolvesDraftFields(): void
    {
        $uuid = $this->seedDraft('Hello Draft');
        $token = $this->minter()->mint($uuid, 'en', null);

        $payload = $this->reader()->read($token);

        self::assertSame($uuid, $payload['entry_uuid']);
        self::assertSame('en', $payload['locale']);
        self::assertSame('Hello Draft', $payload['fields']['title']);
        self::assertNull($payload['version_uuid']);
    }

    public function testReadResolvesPinnedVersionFields(): void
    {
        $uuid = $this->seedDraft('Original Draft');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        );
        $versionUuid = $publish->publish($uuid, 'en', 'tester');

        // Move the draft on so draft != version, proving the reader serves the version.
        $this->entries()->saveDraft($uuid, 'en', ['title' => 'Newer Draft'], 1, 1, 'tester');

        $token = $this->minter()->mint($uuid, 'en', $versionUuid);
        $payload = $this->reader()->read($token);

        self::assertSame($versionUuid, $payload['version_uuid']);
        self::assertSame('Original Draft', $payload['fields']['title']);
    }

    public function testReadThrowsNotFoundForNonExistentEntry(): void
    {
        $token = $this->minter()->mint('does-not-xx', 'en', null);

        $this->expectException(PreviewNotFoundException::class);
        $this->reader()->read($token);
    }

    public function testReadPropagatesExpiredToken(): void
    {
        $uuid = $this->seedDraft('Whatever');
        // Mint directly with an already-past expiry using the same key the minter uses.
        $key = (string) config($this->appContext(), 'app.key', '');
        $token = PreviewToken::mint($uuid, 'en', null, time() - 1, $key);

        try {
            $this->reader()->read($token);
            self::fail('Expected expired PreviewTokenException');
        } catch (PreviewTokenException $e) {
            self::assertTrue($e->isExpired());
        }
    }

    public function testReadPropagatesTamperedToken(): void
    {
        $uuid = $this->seedDraft('Tamper Me');
        $token = $this->minter()->mint($uuid, 'en', null);
        // Flip the last character of the signature part.
        $tampered = substr($token, 0, -1) . ($token[-1] === 'A' ? 'B' : 'A');

        $this->expectException(PreviewTokenException::class);
        $this->expectExceptionCode(PreviewTokenException::INVALID_SIGNATURE);
        $this->reader()->read($tampered);
    }

    public function testReadRejectsVersionBelongingToAnotherEntry(): void
    {
        // Entry A owns a real published version.
        $entryA = $this->seedDraft('A draft');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        );
        $versionA = $publish->publish($entryA, 'en', 'tester');

        // Entry B exists with its own draft. A token names entry B but points at
        // entry A's version_uuid — the reader must refuse to serve A's content.
        $entryB = $this->seedDraft('B draft');
        $token = $this->minter()->mint($entryB, 'en', $versionA);

        $this->expectException(PreviewNotFoundException::class);
        $this->reader()->read($token);
    }

    public function testReadRejectsVersionBelongingToAnotherLocale(): void
    {
        // The entry owns a real published 'en' version. A token names the SAME
        // entry but a different locale ('fr') while pointing at the 'en'
        // version_uuid — the reader must refuse (the locale half of the binding).
        $entry = $this->seedDraft('Locale-bound');
        $publish = new PublishService(
            $this->appContext(),
            $this->entries(),
            $this->versions(),
            new ContentTypeRepository($this->connection()),
            new FieldValidator(),
        );
        $versionEn = $publish->publish($entry, 'en', 'tester');

        $token = $this->minter()->mint($entry, 'fr', $versionEn);

        $this->expectException(PreviewNotFoundException::class);
        $this->reader()->read($token);
    }
}
