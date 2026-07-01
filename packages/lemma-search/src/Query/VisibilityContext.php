<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Query;

/** The resolved visibility for one request. */
final class VisibilityContext
{
    /** @param list<string> $scopedTypeUuids */
    public function __construct(
        public readonly bool $allAccess,
        public readonly array $scopedTypeUuids,
    ) {
    }
}
