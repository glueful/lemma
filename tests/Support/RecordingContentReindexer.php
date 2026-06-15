<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Content\Search\ContentReindexerInterface;

final class RecordingContentReindexer implements ContentReindexerInterface
{
    /** @var list<array{entry: string, locale: string}> */
    public array $requests = [];

    public function reindexEntry(string $entryUuid, string $locale): void
    {
        $this->requests[] = [
            'entry' => $entryUuid,
            'locale' => $locale,
        ];
    }
}
