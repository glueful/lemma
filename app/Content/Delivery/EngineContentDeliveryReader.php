<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;

/**
 * Adapts DeliveryRepository (publication-spine queries) to the ContentDeliveryReader
 * contract. find tries route(slug) first, then falls back to uuid lookup.
 */
final class EngineContentDeliveryReader implements ContentDeliveryReader
{
    public function __construct(private readonly DeliveryRepository $delivery)
    {
    }

    public function listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array
    {
        return $this->delivery->listPublished($contentTypeUuid, $locale, $limit);
    }

    public function findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array
    {
        return $this->delivery->findPublishedByRoute($contentTypeUuid, $locale, $slugOrUuid)
            ?? $this->delivery->findPublishedByUuid($contentTypeUuid, $locale, $slugOrUuid);
    }
}
