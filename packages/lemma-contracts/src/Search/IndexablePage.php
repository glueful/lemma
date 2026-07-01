<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Search;

/** A page of IndexableContent for backfill. */
final class IndexablePage
{
    /** @param list<IndexableContent> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
