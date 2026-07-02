<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Seo\RouteResolver;
use Glueful\Database\Connection;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;

/**
 * Path → published content for the render pack, wrapping the existing RouteResolver.
 * Owns raw-path parsing (the inverse of PathRenderer's /{locale}/{type}/{slug} template)
 * and NORMALIZATION-FIRST canonical redirects (render spec §3): /blog//hello 301s before
 * any parsing or lookup, so content resolution only ever sees canonical paths. Render is
 * an ANONYMOUS surface: non-public-delivery types resolve not_found even with a live
 * route ({@see DeliveryVisibility} with null scopes — the same rule anonymous delivery
 * enforces).
 */
final class EnginePublicRouteResolver implements PublicRouteResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly RouteResolver $routes,
        private readonly LocaleManagerInterface $locales,
        private readonly DeliveryItemShaper $shaper,
    ) {
    }

    public function resolvePath(string $path): array
    {
        $raw = $path === '' ? '/' : $path;
        $normalized = $this->normalize($raw);
        if ($normalized !== $raw) {
            return $this->redirect($normalized, 301);
        }

        $segments = array_map(rawurldecode(...), array_values(array_filter(
            explode('/', trim($normalized, '/')),
            static fn(string $s): bool => $s !== '',
        )));

        // 3 segments with an ACTIVE locale first → locale variant; 2 → default locale.
        // "/blog/hello" is type blog in the default locale — NEVER locale "blog".
        if (count($segments) === 3 && $this->isActiveLocale($segments[0])) {
            [$locale, $typeSlug, $slug] = $segments;
        } elseif (count($segments) === 2) {
            [$typeSlug, $slug] = $segments;
            $locale = $this->locales->default();
        } else {
            return $this->notFound();
        }

        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];

        $result = $this->routes->resolve($typeUuid, $typeSlug, $this->localeChain($locale), $slug);
        if ($result === null) {
            return $this->notFound();
        }
        if ($result->isGone()) {
            return ['kind' => 'gone', 'locale' => $locale, 'content' => null, 'redirect' => null];
        }
        if ($result->isRedirect()) {
            $descriptor = $result->redirect();
            return $this->redirect((string) $descriptor['to'], (int) $descriptor['status']);
        }

        $row = $result->content();
        return [
            'kind' => 'content',
            'locale' => (string) $row['locale'],
            'content' => $this->shaper->shapePublic($row, $typeUuid, $typeSlug),
            'redirect' => null,
        ];
    }

    public function resolveEntry(string $entryUuid, ?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? $locale : $this->locales->default();

        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)->first();
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return $this->notFound();
        }
        $typeRow = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return $this->notFound();
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];

        // ROUTELESS is not_found here: a published entry with no route cannot be a
        // homepage target (the EntryTargetResolver rule — no consumer renders unroutable
        // content). Redirect/gone from a uuid resolution are also not_found: a homepage
        // must point at live content directly.
        $route = $this->db->table('entry_routes')->select(['slug'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($route === null) {
            return $this->notFound();
        }

        $result = $this->routes->resolve($typeUuid, $typeSlug, $this->localeChain($locale), $entryUuid);
        if ($result === null || !$result->isContent()) {
            return $this->notFound();
        }

        $row = $result->content();
        return [
            'kind' => 'content',
            'locale' => (string) $row['locale'],
            'content' => $this->shaper->shapePublic($row, $typeUuid, $typeSlug),
            'redirect' => null,
        ];
    }

    private function normalize(string $path): string
    {
        $collapsed = preg_replace('#/{2,}#', '/', '/' . trim($path, " \t")) ?? $path;
        $collapsed = str_starts_with($collapsed, '//') ? substr($collapsed, 1) : $collapsed;
        $trimmed = rtrim($collapsed, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    private function isActiveLocale(string $code): bool
    {
        foreach ($this->locales->enabled() as $row) {
            if ((string) ($row['code'] ?? '') === $code) {
                return true;
            }
        }
        return false;
    }

    /**
     * Requested locale first + the i18n fallback chain, deduped — mirrors the delivery
     * API's locale chain so render resolves exactly what the API would.
     *
     * @return non-empty-list<string>
     */
    private function localeChain(string $requested): array
    {
        $chain = $this->locales->fallbackChain($requested);
        array_unshift($chain, $requested);
        $out = [];
        foreach ($chain as $locale) {
            $locale = trim((string) $locale);
            if ($locale !== '') {
                $out[$locale] = $locale;
            }
        }
        return $out === [] ? [$requested] : array_values($out);
    }

    /** @param array<string,mixed> $typeRow */
    private function isPubliclyDeliverable(array $typeRow): bool
    {
        return DeliveryVisibility::isAccessible(
            (bool) ($typeRow['public_delivery'] ?? false),
            (string) ($typeRow['slug'] ?? ''),
            null, // anonymous — render never carries API-key scopes
        );
    }

    /** @return array{kind: string, locale: null, content: null, redirect: array{location: string, status: int}} */
    private function redirect(string $location, int $status): array
    {
        return ['kind' => 'redirect', 'locale' => null, 'content' => null,
            'redirect' => ['location' => $location, 'status' => $status]];
    }

    /** @return array{kind: string, locale: null, content: null, redirect: null} */
    private function notFound(): array
    {
        return ['kind' => 'not_found', 'locale' => null, 'content' => null, 'redirect' => null];
    }
}
