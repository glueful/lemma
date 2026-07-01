<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Search\ContentReindexer;

/** Bound when lemma.search is disabled: the reindex listener resolves this and no-ops. */
final class NullContentReindexer implements ContentReindexer
{
    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
    }
}
