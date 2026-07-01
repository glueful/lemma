<?php

declare(strict_types=1);

namespace Glueful\Lemma\Seo\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Seo\Meta\SeoMetaResolver;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public per-entry SEO meta for the frontend <head>. Resolution: override → per-type
 * fallback → site default. No auth — published content only.
 */
final class SeoMetaController
{
    public function __construct(
        private readonly SeoMetaResolver $resolver,
        private readonly ContentTypeReader $types,
    ) {
    }

    /**
     * Resolved SEO meta (title, description, Open Graph, Twitter card, robots) for a
     * published entry, for injection into the frontend `<head>`.
     *
     * @queryParam locale:string="Locale to resolve; defaults to en."
     */
    #[ApiOperation(
        summary: 'Resolved SEO meta for a published entry',
        description: 'Resolution per field: per-entry override → per-type fallback field → site default. '
            . 'Canonical/hreflang are intentionally absent — they live on the core delivery `seo` object.',
        tags: ['Lemma SEO'],
    )]
    #[ApiResponse(200, description: 'Resolved meta descriptor for the entry+locale.')]
    #[ApiResponse(404, description: 'Unknown content type, or no published entry for the route.')]
    public function show(Request $request, string $type, string $slug): Response
    {
        $locale = (string) $request->query->get('locale', 'en');
        $typeUuid = $this->types->findUuidBySlug($type);
        if ($typeUuid === null) {
            return Response::error('Unknown content type.', 404);
        }
        $meta = $this->resolver->resolve($typeUuid, $type, $slug, $locale);
        if ($meta === null) {
            return Response::error('No published entry for this route.', 404);
        }
        return Response::success($meta);
    }
}
