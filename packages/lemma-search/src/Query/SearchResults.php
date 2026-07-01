<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

final class SearchResults
{
    /** @param list<Hit> $hits */
    public function __construct(
        public readonly array $hits,
        public readonly int $total,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
