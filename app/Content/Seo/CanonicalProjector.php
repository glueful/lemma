<?php

declare(strict_types=1);

namespace App\Content\Seo;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;

final class CanonicalProjector
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
        private readonly PathRenderer $paths,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    /** @return array{canonical:?array<string,mixed>,alternates:list<array<string,mixed>>,x_default:?array<string,mixed>} */
    public function project(string $entryUuid, string $contentTypeUuid, string $contentTypeSlug, string $locale): array
    {
        $routes = $this->routes->forEntry($entryUuid);
        $alternates = [];
        $canonical = null;
        $xDefault = null;

        foreach ($this->delivery->publishedPinsForEntry($entryUuid) as $pin) {
            $pinTypeUuid = (string) $pin['type'];
            $pinLocale = (string) $pin['locale'];
            $typeSlug = $pinTypeUuid === $contentTypeUuid
                ? $contentTypeSlug
                : $this->typeSlug($pinTypeUuid);
            $slug = $this->slugFor($routes, $pinTypeUuid, $pinLocale);
            if ($typeSlug === null || $slug === null) {
                continue;
            }

            $alternate = [
                'locale' => $pinLocale,
                'href' => $this->paths->render($typeSlug, $pinLocale, $slug),
                'content_type' => $typeSlug,
                'slug' => $slug,
            ];
            $alternates[] = $alternate;
            if ($pinLocale === $locale) {
                $canonical = $alternate;
            }
            if ($pinLocale === $this->defaultLocale) {
                $xDefault = [
                    'locale' => $pinLocale,
                    'href' => $this->paths->renderDefaultLocale($typeSlug, $slug),
                    'content_type' => $typeSlug,
                    'slug' => $slug,
                ];
            }
        }

        usort(
            $alternates,
            static fn (array $a, array $b): int => strcmp((string) $a['locale'], (string) $b['locale'])
        );

        return [
            'canonical' => $canonical,
            'alternates' => $alternates,
            'x_default' => $xDefault,
        ];
    }

    /** @param list<array<string,mixed>> $routes */
    private function slugFor(array $routes, string $contentTypeUuid, string $locale): ?string
    {
        foreach ($routes as $route) {
            if (
                (string) $route['content_type_uuid'] === $contentTypeUuid
                && (string) $route['locale'] === $locale
            ) {
                return (string) $route['slug'];
            }
        }

        return null;
    }

    private function typeSlug(string $contentTypeUuid): ?string
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        return $type === null ? null : (string) $type['slug'];
    }
}
