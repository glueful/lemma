<?php

declare(strict_types=1);

namespace App\Content\Search;

/**
 * Provider-neutral seam for search extensions that can reindex published content.
 *
 * Lemma owns the content lifecycle and sends only identity data. The installed search
 * provider owns document construction, indexing, queueing, and adapter-specific behavior.
 */
interface ContentReindexerInterface
{
    public function reindexEntry(string $entryUuid, string $locale): void;
}
