<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Search;

/**
 * Reindex a single published entry/locale into a pack-owned search index.
 * Implemented by core's no-op default and by a search pack when installed.
 */
interface ContentReindexer
{
    public function reindexEntry(string $entryUuid, string $locale): void;
}
