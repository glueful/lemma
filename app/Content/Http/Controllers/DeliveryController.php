<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Delivery\Cursor;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Delivery\UnfilterableFieldException;
use App\Content\Delivery\InvalidFilterException;
use App\Content\Http\DeliveryEtag;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Http\DTOs\ErrorResponse;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * The public delivery API — serves ONLY published content.
 *
 * Every read goes through {@see DeliveryRepository}, whose queries join the publication
 * spine so drafts/unpublished/archived entries physically cannot leak. Both list paths
 * (cursor/keyset by default, framework-offset when `?page`/`?perPage` is present) apply
 * the same {@see FilterCompiler}/{@see SortCompiler}, resolve references via
 * {@see ReferenceResolver}, shape output through the framework {@see Projector} against a
 * schema-derived allow-list, and emit ETag/Cache-Control/Cache-Tag validators via
 * {@see DeliveryEtag}.
 */
final class DeliveryController
{
    /**
     * @param DeliveryRepository    $delivery publication-spine reads (published-only)
     * @param ContentTypeRepository $types    resolves the {type} slug to its schema
     * @param FilterCompiler        $filters  compiles `filter[...]` against filterable fields
     * @param SortCompiler          $sorts    compiles `sort` against filterable fields
     * @param ReferenceResolver     $references batch-expands reference/asset fields (no N+1)
     * @param Projector             $projector applies `fields`/`expand` to the `fields` object
     * @param DeliveryEtag          $etags    ETag/Cache-Control/Cache-Tag validators
     */
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DeliveryRepository $delivery,
        private readonly ContentTypeRepository $types,
        private readonly FilterCompiler $filters,
        private readonly SortCompiler $sorts,
        private readonly ReferenceResolver $references,
        private readonly Projector $projector,
        private readonly DeliveryEtag $etags,
    ) {
    }

    /**
     * List published entries of the {type} content type. Resolves the slug (404 if unknown),
     * compiles filter+sort against the schema (a non-filterable field or bad operator → 422),
     * then branches on pagination mode: explicit `?page`/`?perPage` uses the framework offset
     * envelope, otherwise a keyset cursor list returning `items` + `next_cursor`. Either way
     * rows are shaped (reference-expanded + field-projected) and decorated with cache headers.
     *
     * @param string $type Content type slug
     */
    #[ApiOperation(
        summary: 'List published entries of a content type',
        description: 'Returns a page of PUBLISHED entries for the content type identified by the {type} '
            . 'slug. Reads only through the publication spine, so drafts/unpublished/archived entries are '
            . 'never returned. Requires an API key carrying the `read:content` scope (header `X-API-Key`); '
            . 'rate-limited to 120 requests/minute per key. Two pagination modes: by default a keyset '
            . 'cursor (stable under publish churn) returns `data.items` + `data.next_cursor`; passing '
            . '`page`/`perPage` switches to an offset envelope with top-level '
            . '`current_page`/`per_page`/`total`/`total_pages`. Filtering and sorting are accepted only on '
            . 'fields the content type marks filterable — anything else returns 422.',
        tags: ['Lemma Delivery'],
    )]
    #[QueryParam(
        'filter',
        'string',
        description: 'Typed filters on filterable fields using bracket syntax `filter[field][op]=value`. '
            . 'Operators: eq, neq, gt, gte, lt, lte, in. Only fields declared filterable are accepted.',
    )]
    #[QueryParam(
        'sort',
        'string',
        description: 'Sort by a filterable field, `sort=field:asc` or `sort=field:desc`. '
            . 'Defaults to `published_at:desc`.',
    )]
    #[QueryParam(
        'locale',
        'string',
        description: 'Content locale to read (defaults to lemma.default_locale, e.g. `en`).',
    )]
    #[QueryParam(
        'cursor',
        'string',
        description: 'Opaque keyset cursor taken from a previous response\'s `next_cursor`. '
            . 'Cursor (default) mode only.',
    )]
    #[QueryParam(
        'page',
        'integer',
        description: 'Page number. Supplying `page` or `perPage` switches the response to the '
            . 'offset-pagination envelope.',
    )]
    #[QueryParam(
        'perPage',
        'integer',
        description: 'Items per page for offset pagination (clamped to delivery.max_per_page).',
    )]
    #[ApiResponse(
        200,
        description: 'A page of published entries (cursor mode by default; offset mode replaces `data` '
            . 'with the item array plus top-level pagination keys).',
    )]
    #[ApiResponse(
        401,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Missing or invalid authentication.',
    )]
    #[ApiResponse(
        403,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'API key lacks the required `read:content` scope.',
    )]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Filter or sort references a non-filterable field or an unsupported operator.',
    )]
    #[ApiResponse(
        429,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Rate limit exceeded (120/minute per key).',
    )]
    #[ApiResponse(500, schema: ErrorResponse::class, envelope: false, description: 'Unexpected server error.')]
    public function index(Request $request, string $type): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($request);

        try {
            $filter = $this->compileFilter($request, $schema);
            $order = $this->sorts->compile($schema, $this->stringQuery($request, 'sort'));
        } catch (UnfilterableFieldException | InvalidFilterException $e) {
            return Response::validation(['filter' => $e->getMessage()]);
        }

        $selector = FieldSelector::fromRequest($request);

        // Pagination branch: explicit ?page/?perPage uses the framework offset envelope.
        if ($this->wantsPagination($request)) {
            [$page, $perPage] = $this->pageParams($request);
            $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, $order);
            $rows = $this->shape($result['data'], $schema, $selector, $locale);
            $response = Response::paginated(
                array_map(fn(array $r): array => $this->item($r), $rows),
                $result['total'],
                $result['current_page'],
                $result['per_page'],
            );
            return $this->withCacheHeaders($request, $response, $rows, $type);
        }

        // Default: cursor/keyset list.
        $limit = $this->limit($request);
        $cursor = Cursor::decode($this->stringQuery($request, 'cursor') ?? '');
        $rows = $this->delivery->listPublished($typeUuid, $locale, $limit, $filter, $order, $cursor);
        $shaped = $this->shape($rows, $schema, $selector, $locale);

        $nextCursor = null;
        if (count($rows) === $limit && $rows !== []) {
            $nextCursor = Cursor::encode($this->delivery->cursorFor($rows[count($rows) - 1], $order));
        }

        $response = Response::success([
            'items' => array_map(fn(array $r): array => $this->item($r), $shaped),
            'next_cursor' => $nextCursor,
        ], 'Content retrieved.');

        return $this->withCacheHeaders($request, $response, $shaped, $type);
    }

    /**
     * Return one published entry of {type}, resolved by route slug first and falling back to a
     * UUID lookup only when the value looks like a 12-char nanoid. A draft-only/unpublished
     * target yields 404 (the publication spine hides it). Emits an ETag keyed to the published
     * version + selection; a matching `If-None-Match` short-circuits to 304 Not Modified.
     *
     * @param string $type       Content type slug
     * @param string $slugOrUuid Route slug, or the entry's 12-char UUID
     */
    #[ApiOperation(
        summary: 'Get a single published entry by slug or UUID',
        description: 'Returns one PUBLISHED entry of the {type} content type, resolved by its route slug or '
            . 'by its 12-char entry UUID. Only published content is reachable — a draft-only or '
            . 'unpublished entry yields 404. Requires an API key with the `read:content` scope. Emits an '
            . '`ETag`; send it back as `If-None-Match` to receive a `304 Not Modified` when the published '
            . 'version is unchanged. Field projection and reference expansion via `fields`/`expand`.',
        tags: ['Lemma Delivery'],
    )]
    #[QueryParam('locale', 'string', description: 'Content locale to read (defaults to lemma.default_locale).')]
    #[ApiResponse(200, description: 'The published entry.')]
    #[ApiResponse(
        304,
        description: 'Not Modified — the supplied If-None-Match ETag still matches the published version.',
    )]
    #[ApiResponse(
        401,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Missing or invalid authentication.',
    )]
    #[ApiResponse(
        403,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'API key lacks the required `read:content` scope.',
    )]
    #[ApiResponse(
        404,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Unknown content type, or no published entry for the given slug/UUID.',
    )]
    #[ApiResponse(
        429,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Rate limit exceeded (120/minute per key).',
    )]
    #[ApiResponse(500, schema: ErrorResponse::class, envelope: false, description: 'Unexpected server error.')]
    public function show(Request $request, string $type, string $slugOrUuid): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($request);

        // Slug/route first; fall back to a uuid lookup when it looks like a nanoid.
        $row = $this->delivery->findPublishedByRoute($typeUuid, $locale, $slugOrUuid);
        if ($row === null && $this->looksLikeNanoid($slugOrUuid)) {
            $row = $this->delivery->findPublishedByUuid($typeUuid, $locale, $slugOrUuid);
        }
        if ($row === null) {
            return Response::notFound('Content not found.');
        }

        $selector = FieldSelector::fromRequest($request);
        $shaped = $this->shape([$row], $schema, $selector, $locale);
        $item = $this->item($shaped[0]);

        $etag = $this->etags->forItem((string) $row['version_uuid'], $this->selectionKey($request));
        if ($this->etags->matches($request, $etag)) {
            return $this->etags->notModified(
                $etag,
                $this->ttl(),
                $this->etags->cacheTag([(string) $row['entry_uuid']], $type),
            );
        }

        $response = Response::success($item, 'Content retrieved.');
        return $this->etags->applyHeaders(
            $response,
            $etag,
            $this->ttl(),
            $this->etags->cacheTag([(string) $row['entry_uuid']], $type),
        );
    }

    /**
     * Resolve references then project each row's schema fields against the schema-derived
     * allow-list. The envelope keys (uuid/version/published_at) are not projectable — only
     * the `fields` sub-object honours `?fields`.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function shape(array $rows, ContentTypeSchema $schema, FieldSelector $selector, string $locale): array
    {
        if ($rows === []) {
            return [];
        }

        $rows = $this->references->expand($rows, $schema, $selector->empty() ? null : $selector, $locale);

        if ($selector->empty()) {
            return $rows;
        }

        $allowed = array_map(static fn($f): string => $f->name, $schema->fields());
        foreach ($rows as $i => $row) {
            /** @var array<string,mixed> $fields */
            $fields = $row['fields'] ?? [];
            /** @var array<string,mixed> $projected */
            $projected = (array) $this->projector->project($fields, $selector, $allowed);
            $rows[$i]['fields'] = $projected;
        }
        return $rows;
    }

    /**
     * The public envelope for one hydrated row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function item(array $row): array
    {
        return [
            'uuid' => $row['entry_uuid'] ?? null,
            'locale' => $row['locale'] ?? null,
            'version' => $row['version'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'fields' => $row['fields'] ?? [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows the SHAPED rows (still carrying envelope keys)
     */
    private function withCacheHeaders(
        Request $request,
        Response $response,
        array $rows,
        string $typeSlug,
    ): Response {
        $versionUuids = array_map(static fn(array $r): string => (string) ($r['version_uuid'] ?? ''), $rows);
        $entryUuids = array_map(static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''), $rows);
        $etag = $this->etags->forList($versionUuids, $this->selectionKey($request));
        return $this->etags->applyHeaders(
            $response,
            $etag,
            $this->ttl(),
            $this->etags->cacheTag($entryUuids, $typeSlug),
        );
    }

    /**
     * @return array{sql:string,bindings:list<mixed>}|null
     */
    private function compileFilter(Request $request, ContentTypeSchema $schema): ?array
    {
        $raw = $request->query->all('filter');
        if ($raw === []) {
            return null;
        }
        return $this->filters->compile($schema, $raw);
    }

    /**
     * Whether the request opts into offset pagination. Presence of either `page` or `perPage`
     * switches index() from the default keyset-cursor list to the framework offset envelope.
     */
    private function wantsPagination(Request $request): bool
    {
        return $request->query->has('page') || $request->query->has('perPage');
    }

    /** @return array{0:int,1:int} */
    private function pageParams(Request $request): array
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = (int) $request->query->get('perPage', (string) $this->defaultPerPage());
        return [$page, $this->clampPerPage($perPage)];
    }

    /**
     * The page size for the cursor list path: the requested `perPage` (clamped) or the
     * configured default. Doubles as the "is there a next page?" probe in index(), which asks
     * for exactly this many rows and emits a cursor only when the result is full.
     */
    private function limit(Request $request): int
    {
        $perPage = $request->query->has('perPage')
            ? (int) $request->query->get('perPage')
            : $this->defaultPerPage();
        return $this->clampPerPage($perPage);
    }

    /**
     * Clamp a requested page size into the safe range: a non-positive value falls back to the
     * default, and the result is capped at `lemma.delivery.max_per_page` so a client cannot
     * request an unbounded page.
     */
    private function clampPerPage(int $perPage): int
    {
        $max = (int) config($this->context, 'lemma.delivery.max_per_page', 100);
        if ($perPage < 1) {
            $perPage = $this->defaultPerPage();
        }
        return min($perPage, $max);
    }

    /** The configured default page size (`lemma.delivery.default_per_page`, fallback 20). */
    private function defaultPerPage(): int
    {
        return (int) config($this->context, 'lemma.delivery.default_per_page', 20);
    }

    /**
     * The Cache-Control max-age (seconds) advertised on delivery responses, from
     * `lemma.delivery.cache_ttl` (fallback 60).
     */
    private function ttl(): int
    {
        return (int) config($this->context, 'lemma.delivery.cache_ttl', 60);
    }

    /**
     * The locale to read: the `locale` query param when present and non-empty, otherwise the
     * configured `lemma.default_locale`.
     */
    private function locale(Request $request): string
    {
        $locale = $this->stringQuery($request, 'locale');
        if ($locale !== null && $locale !== '') {
            return $locale;
        }
        return (string) config($this->context, 'lemma.default_locale', 'en');
    }

    /**
     * Read a query param as a string, or null when it is absent or an array (e.g. `key[]=`),
     * so callers can treat it as a plain optional scalar without type juggling.
     */
    private function stringQuery(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);
        return is_string($value) ? $value : null;
    }

    /**
     * A stable key for the response shape, so the ETag changes when the requested fields,
     * expansions, sort, filter or locale change.
     */
    private function selectionKey(Request $request): string
    {
        $parts = [
            'fields=' . (string) $request->query->get('fields', ''),
            'expand=' . (string) $request->query->get('expand', ''),
            'sort=' . (string) $request->query->get('sort', ''),
            'locale=' . $this->locale($request),
            'filter=' . json_encode($request->query->all('filter')),
        ];
        return implode('&', $parts);
    }

    /**
     * Whether the value has the shape of a 12-char entry nanoid (the alphabet show() falls
     * back to a UUID lookup on). A cheap shape check only — it does not assert the id exists.
     */
    private function looksLikeNanoid(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{12}\z/', $value) === 1;
    }
}
