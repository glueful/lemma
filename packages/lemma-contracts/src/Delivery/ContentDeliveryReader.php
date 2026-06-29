<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Read-only access to PUBLISHED content. Never exposes drafts. Consumed by render,
 * search, and reference-resolving packs.
 */
interface ContentDeliveryReader
{
    /** @return array<int,array<string,mixed>> Published rows for the type/locale. */
    public function listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array;

    /** @return array<string,mixed>|null One published row (by slug, else uuid) or null. */
    public function findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array;
}
