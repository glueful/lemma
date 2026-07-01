<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;

/**
 * Adapts DeliveryRepository (publication-spine queries) to the ContentDeliveryReader
 * contract. find tries route(slug) first, then falls back to uuid lookup.
 */
final class EngineContentDeliveryReader implements ContentDeliveryReader
{
    /** @var array<string,string>|null uuid => type slug, lazily built */
    private ?array $typeSlugs = null;

    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly PathRenderer $paths,
        private readonly CanonicalProjector $canonical,
        private readonly ContentTypeRepository $types,
    ) {
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

    public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array
    {
        $page = $this->delivery->enumeratePublishedForSitemap($limit, $offset);
        $slugs = $this->typeSlugMap();

        $items = [];
        foreach ($page['rows'] as $row) {
            $typeUuid = (string) $row['content_type_uuid'];
            $typeSlug = $slugs[$typeUuid] ?? null;
            if ($typeSlug === null) {
                continue; // orphaned type — skip rather than emit a broken URL
            }
            $locale = (string) $row['locale'];
            $slug = (string) $row['slug'];
            $entryUuid = (string) $row['entry_uuid'];

            $alternates = [];
            foreach ($this->canonical->project($entryUuid, $typeUuid, $typeSlug, $locale)['alternates'] as $alt) {
                $alternates[] = ['locale' => (string) $alt['locale'], 'href' => (string) $alt['href']];
            }

            $items[] = [
                'href' => $this->paths->render($typeSlug, $locale, $slug),
                'lastmod' => $this->iso($row['published_at'] ?? null),
                'alternates' => $alternates,
            ];
        }

        return ['items' => $items, 'total' => $page['total'], 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array<string,string> uuid => slug */
    private function typeSlugMap(): array
    {
        if ($this->typeSlugs === null) {
            $this->typeSlugs = [];
            foreach ($this->types->all() as $type) {
                $this->typeSlugs[(string) $type['uuid']] = (string) $type['slug'];
            }
        }
        return $this->typeSlugs;
    }

    private function iso(mixed $publishedAt): ?string
    {
        if (!is_string($publishedAt) || $publishedAt === '') {
            return null;
        }
        $ts = strtotime($publishedAt);
        return $ts === false ? null : date('c', $ts);
    }
}
