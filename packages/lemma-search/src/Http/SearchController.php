<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Http;

use Glueful\Http\Response;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\VisibilityResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public content search. Behind `optional_api_key`: an authenticated key narrows visibility
 * to its scopes; anonymous sees only public_delivery content. Visibility is enforced inside
 * the backend filter, so `total`/pagination are correct.
 */
final class SearchController
{
    public function __construct(
        private readonly SearchBackend $backend,
        private readonly VisibilityResolver $visibility,
        private readonly ContentTypeReader $types,
        private readonly int $defaultLimit,
        private readonly int $maxLimit,
    ) {
    }

    public function search(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') {
            return Response::error('A non-empty `q` query parameter is required.', 422);
        }

        $locale = trim((string) $request->query->get('locale', ''));
        if ($locale === '') {
            return Response::error('A `locale` query parameter is required.', 422);
        }

        // null = anonymous (no key); an array (possibly empty) = an authenticated key.
        $grantedScopes = $request->attributes->has('api_key_scopes')
            ? array_values(array_filter((array) $request->attributes->get('api_key_scopes', []), 'is_string'))
            : null;
        $ctx = $this->visibility->resolve($grantedScopes);

        $typeSlug = trim((string) $request->query->get('type', ''));
        if ($typeSlug !== '') {
            $typeUuid = $this->types->findUuidBySlug($typeSlug);
            if ($typeUuid === null) {
                return Response::notFound('Content type not found.');
            }
            if (!$this->visibility->isTypeAccessible($ctx, $typeUuid)) {
                return Response::forbidden('This content type requires a scoped API key');
            }
        } else {
            $typeSlug = null;
        }

        if (!$this->backend->health()) {
            return Response::error('Search is temporarily unavailable.', 503);
        }

        $limit = $this->clamp((int) $request->query->get('limit', $this->defaultLimit), 1, $this->maxLimit);
        $offset = max(0, (int) $request->query->get('offset', 0));

        $results = $this->backend->search(new SearchRequest(
            q: $q,
            locale: $locale,
            typeSlug: $typeSlug,
            allAccess: $ctx->allAccess,
            scopedTypeUuids: $ctx->scopedTypeUuids,
            limit: $limit,
            offset: $offset,
        ));

        $hits = [];
        foreach ($results->hits as $hit) {
            $hits[] = [
                'uuid' => $hit->entryUuid,
                'type' => $hit->contentTypeSlug,
                'locale' => $hit->locale,
                'href' => $hit->href,
                'title' => $hit->title,
                'snippet' => $hit->snippet,
                'score' => $hit->score,
            ];
        }

        return Response::success([
            'hits' => $hits,
            'total' => $results->total,
            'limit' => $results->limit,
            'offset' => $results->offset,
        ]);
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
