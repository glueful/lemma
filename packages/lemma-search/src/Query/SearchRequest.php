<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

/**
 * A validated, visibility-resolved search request. `typeSlug` is null (all accessible types)
 * or an already-validated, accessible slug. `allAccess` + `scopedTypeUuids` come from the
 * VisibilityResolver and are enforced inside the backend filter (never post-filtered).
 */
final class SearchRequest
{
    /** @param list<string> $scopedTypeUuids */
    public function __construct(
        public readonly string $q,
        public readonly string $locale,
        public readonly ?string $typeSlug,
        public readonly bool $allAccess,
        public readonly array $scopedTypeUuids,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }
}
