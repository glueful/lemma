<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Tests\Support\LemmaTestCase;

final class EntryRepositoryTest extends LemmaTestCase
{
    private function repo(): EntryRepository
    {
        return new EntryRepository(
            $this->connection(),
            $this->appContext(),
            new ContentTypeRepository($this->connection()),
        );
    }

    public function testCreateEntryStartsAnEmptyDraft(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $draft = $this->repo()->findDraft($entry, 'en');
        self::assertSame(0, $draft['lock_version']);
        self::assertSame([], $draft['fields']);
    }

    public function testSaveDraftIncrementsLockVersion(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'A'], 1, 0, 'user00000001');
        self::assertSame(1, $this->repo()->findDraft($entry, 'en')['lock_version']);
    }

    public function testStaleSaveThrows409(): void
    {
        $entry = $this->repo()->createEntry('ctype0000001', 'en', 1, 'user00000001');
        $this->repo()->saveDraft($entry, 'en', ['title' => 'A'], 1, 0, 'user00000001'); // now lock_version=1
        $this->expectException(OptimisticLockException::class);
        $this->repo()->saveDraft($entry, 'en', ['title' => 'B'], 1, 0, 'user00000001'); // stale 0
    }
}
