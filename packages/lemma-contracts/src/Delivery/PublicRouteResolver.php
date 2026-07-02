<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Core answers "what does this public path serve?" for the render pack. `content` is the
 * PUBLIC DELIVERY SHAPE for one published entry — `seo` included — already
 * visibility-filtered and route-resolved by core; consumers treat it as READ-ONLY
 * template context (no mutation, no re-normalization). Normalization differences
 * (trailing slash, duplicate slashes) are returned as 301 redirects BEFORE any lookup,
 * so content resolution only ever sees canonical paths.
 */
interface PublicRouteResolver
{
    /**
     * @return array{kind: 'content'|'redirect'|'gone'|'not_found', locale: ?string,
     *   type: ?string, content: ?array, redirect: ?array{location: string, status: int}}
     *   `type` is the content-type slug (content kind only) — the template hierarchy
     *   (entry/{type-slug}.twig) selects on it.
     */
    public function resolvePath(string $path): array;

    /** Same result shape, for a known entry (homepage; previews later). */
    public function resolveEntry(string $entryUuid, ?string $locale = null): array;
}
