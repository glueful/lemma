<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\PathRenderer;
use Glueful\Lemma\Contracts\Search\IndexableContent;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Contracts\Search\IndexablePage;

/**
 * Adapts the delivery spine to the search IndexableContentReader contract. Reads only
 * PUBLISHED content (via DeliveryRepository), builds the href via PathRenderer, and never
 * exposes drafts/unpublished/archived entries.
 */
final class EngineIndexableContentReader implements IndexableContentReader
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly ContentTypeRepository $types,
        private readonly RouteRepository $routes,
        private readonly PathRenderer $paths,
    ) {
    }

    public function getIndexablePublished(string $entryUuid, string $locale): ?IndexableContent
    {
        // Published pins for this entry (one per published locale); null if none for $locale.
        $pin = null;
        foreach ($this->delivery->publishedPinsForEntry($entryUuid) as $p) {
            if ($p['locale'] === $locale) {
                $pin = $p;
                break;
            }
        }
        if ($pin === null) {
            return null;
        }

        $typeUuid = (string) $pin['type'];
        $row = $this->delivery->findPublishedByUuid($typeUuid, $locale, $entryUuid);
        if ($row === null) {
            return null;
        }

        $type = $this->types->findByUuid($typeUuid);
        if ($type === null) {
            return null; // orphaned type — treat as not indexable
        }
        $typeSlug = (string) $type['slug'];

        // Route slug for href (the entry's route in this locale).
        $slug = null;
        foreach ($this->routes->forEntry($entryUuid) as $route) {
            if (
                ($route['locale'] ?? null) === $locale
                && (string) ($route['content_type_uuid'] ?? '') === $typeUuid
            ) {
                $slug = (string) $route['slug'];
                break;
            }
        }
        if ($slug === null) {
            return null; // no public URL → not indexable
        }

        /** @var array<string,mixed> $fields */
        $fields = (array) ($row['fields'] ?? []);

        return new IndexableContent(
            entryUuid: $entryUuid,
            locale: $locale,
            contentTypeUuid: $typeUuid,
            contentTypeSlug: $typeSlug,
            publicDelivery: (bool) ($type['public_delivery'] ?? false),
            href: $this->paths->render($typeSlug, $locale, $slug),
            entryLabel: $slug,
            fields: $fields,
            lastmod: $this->iso($row['published_at'] ?? null),
        );
    }

    public function enumerateIndexablePublished(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): IndexablePage {
        $page = $this->delivery->enumerateIndexable($limit, $offset, $typeSlug, $locale);

        $items = [];
        foreach ($page['rows'] as $row) {
            $slug = (string) $row['slug'];
            $tSlug = (string) $row['content_type_slug'];
            $loc = (string) $row['locale'];
            $fields = is_string($row['fields'] ?? null)
                ? (json_decode((string) $row['fields'], true) ?? [])
                : (array) ($row['fields'] ?? []);

            $items[] = new IndexableContent(
                entryUuid: (string) $row['entry_uuid'],
                locale: $loc,
                contentTypeUuid: (string) $row['content_type_uuid'],
                contentTypeSlug: $tSlug,
                publicDelivery: (bool) ($row['public_delivery'] ?? false),
                href: $this->paths->render($tSlug, $loc, $slug),
                entryLabel: $slug,
                fields: (array) $fields,
                lastmod: $this->iso($row['published_at'] ?? null),
            );
        }

        return new IndexablePage($items, (int) $page['total'], $limit, $offset);
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
