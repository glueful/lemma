<?php

declare(strict_types=1);

namespace Glueful\Lemma\Seo\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Lemma\Seo\Meta\SeoMetaRepository;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin read/write of the seo_meta override table. Behind auth + lemma_permission:seo.manage.
 */
final class AdminSeoMetaController
{
    private const WRITABLE = [
        'title', 'description', 'og_title', 'og_description', 'og_image', 'twitter_card', 'robots',
    ];

    public function __construct(private readonly SeoMetaRepository $repo)
    {
    }

    /**
     * The raw seo_meta override row for an entry+locale (empty object if none set yet).
     *
     * @queryParam locale:string="Locale of the override; defaults to en."
     */
    #[ApiOperation(summary: 'Read SEO meta overrides for an entry', tags: ['Lemma SEO'])]
    #[ApiResponse(200, description: 'The override row, or an empty object when unset.')]
    public function show(Request $request, string $entryUuid): Response
    {
        $locale = (string) $request->query->get('locale', 'en');
        $row = $this->repo->find($entryUuid, $locale);
        return Response::success($row ?? new \stdClass());
    }

    /**
     * Upsert the seo_meta override row for an entry+locale. Body accepts any of:
     * `title`, `description`, `og_title`, `og_description`, `og_image`, `twitter_card`, `robots`.
     *
     * @queryParam locale:string="Locale of the override; defaults to en."
     */
    #[ApiOperation(summary: 'Upsert SEO meta overrides for an entry', tags: ['Lemma SEO'])]
    #[ApiResponse(200, description: 'The stored override row after the upsert.')]
    public function update(Request $request, string $entryUuid): Response
    {
        $locale = (string) $request->query->get('locale', 'en');
        /** @var array<string,mixed> $body */
        $body = (array) json_decode((string) $request->getContent(), true);

        $data = [];
        foreach (self::WRITABLE as $col) {
            if (array_key_exists($col, $body)) {
                $data[$col] = $body[$col];
            }
        }
        $this->repo->upsert($entryUuid, $locale, $data);
        return Response::success($this->repo->find($entryUuid, $locale));
    }
}
