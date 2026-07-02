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
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use App\Content\Http\DTOs\Requests\Delivery\DeliveryShowQuery;
use App\Content\Http\DTOs\Responses\Delivery\DeliveryItemData;
use App\Content\Http\DTOs\Responses\Delivery\DeliveryListData;
use App\Content\Http\DTOs\Responses\Delivery\DeliveryShowItemData;
use App\Http\DTOs\ErrorResponse;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\RouteResolver;
use App\Settings\GeneralSettings;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
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
        private readonly LocaleManagerInterface $locales,
        private readonly RouteResolver $resolver,
        private readonly CanonicalProjector $canonical,
        private readonly ?SchemaProjector $schemaProjector = null,
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
        description: 'Published entries only. Cursor pagination by default; `page`/`perPage` switches to '
            . 'offset. Filter and sort are accepted only on filterable fields.',
        tags: ['Lemma Delivery'],
    )]
    #[ApiResponse(
        200,
        schema: DeliveryListData::class,
        description: 'A page of published entries (cursor mode by default; offset mode replaces `data` '
            . 'with the item array plus top-level pagination keys).',
    )]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Filter or sort references a non-filterable field or an unsupported operator.',
    )]
    // 401/403/429/500 are inferred from middleware + documentation.errors config (ErrorResponse body).
    public function index(Request $request, DeliveryListQuery $query, string $type): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($query->locale);

        try {
            $filter = $this->compileFilter($query->filter, $schema, $locale);
            $order = $this->sorts->compile($schema, $query->sort);
        } catch (UnfilterableFieldException | InvalidFilterException $e) {
            return Response::validation(['filter' => $e->getMessage()]);
        }

        $selector = FieldSelector::fromRequest($request);
        $scopes = $this->grantedScopes($request);

        // Pagination branch: explicit ?page/?perPage uses the framework offset envelope.
        if ($query->wantsPagination()) {
            [$page, $perPage] = $this->pageParams($query);
            $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, $order);
            $rows = $this->shape($result['data'], $schema, $selector, $locale, $typeUuid, $scopes);
            $response = Response::paginated(
                array_map(fn(array $r): array => $this->item($r), $rows),
                $result['total'],
                $result['current_page'],
                $result['per_page'],
            );
            return $this->withCacheHeaders($request, $response, $rows, $typeRow);
        }

        // Default: cursor/keyset list.
        $limit = $this->limit($query);
        $cursor = Cursor::decode($query->cursor ?? '');
        $rows = $this->delivery->listPublished($typeUuid, $locale, $limit, $filter, $order, $cursor);
        $shaped = $this->shape($rows, $schema, $selector, $locale, $typeUuid, $scopes);

        $nextCursor = null;
        if (count($rows) === $limit && $rows !== []) {
            $nextCursor = Cursor::encode($this->delivery->cursorFor($rows[count($rows) - 1], $order));
        }

        $response = Response::success([
            'items' => array_map(fn(array $r): array => $this->item($r), $shaped),
            'next_cursor' => $nextCursor,
        ], 'Content retrieved.');

        return $this->withCacheHeaders($request, $response, $shaped, $typeRow);
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
        description: 'Resolved by route slug or 12-char entry UUID; published only (draft/unpublished → 404). '
            . 'Supports `If-None-Match` → 304.',
        tags: ['Lemma Delivery'],
    )]
    #[ApiResponse(200, schema: DeliveryShowItemData::class, description: 'The published entry with SEO metadata.')]
    #[ApiResponse(
        304,
        description: 'Not Modified — the supplied If-None-Match ETag still matches the published version.',
    )]
    #[ApiResponse(
        404,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Unknown content type, or no published entry for the given slug/UUID.',
    )]
    // 401/403/429/500 are inferred from middleware + documentation.errors config (ErrorResponse body).
    public function show(Request $request, DeliveryShowQuery $query, string $type, string $slugOrUuid): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locales = $this->localeChain($query->locale);

        $result = $this->resolver->resolve($typeUuid, $type, $locales, $slugOrUuid);
        if ($result === null || $result->isGone()) {
            return Response::notFound('Content not found.');
        }
        if ($result->isRedirect()) {
            return $this->redirectResponse($request, $result->redirect(), $type);
        }

        $row = $result->content();

        $selector = FieldSelector::fromRequest($request);
        $shaped = $this->shape(
            [$row],
            $schema,
            $selector,
            (string) $row['locale'],
            $typeUuid,
            $this->grantedScopes($request),
        );
        $item = $this->item($shaped[0]);
        $item['seo'] = $this->canonical->project(
            (string) $row['entry_uuid'],
            $typeUuid,
            $type,
            (string) $row['locale'],
        );

        $private = $this->isScoped($request);
        $etag = $this->etags->forItem((string) $row['version_uuid'], $this->selectionKey($request));
        if ($this->etags->matches($request, $etag)) {
            return $this->etags->notModified(
                $etag,
                $this->ttl($typeRow),
                $this->etags->cacheTag([(string) $row['entry_uuid']], $type),
                $private,
            );
        }

        $response = Response::success($item, 'Content retrieved.');
        return $this->etags->applyHeaders(
            $response,
            $etag,
            $this->ttl($typeRow),
            $this->etags->cacheTag([(string) $row['entry_uuid']], $type),
            $private,
        );
    }

    /**
     * @param array<string,mixed> $descriptor
     */
    private function redirectResponse(Request $request, array $descriptor, string $typeSlug): Response
    {
        $public = $this->publicRedirectDescriptor($descriptor);
        $etag = '"' . sha1((string) json_encode($descriptor, JSON_THROW_ON_ERROR)) . '"';
        if ($this->etags->matches($request, $etag)) {
            return $this->etags->notModified(
                $etag,
                $this->redirectTtl(),
                $this->redirectCacheTag($descriptor, $typeSlug)
            );
        }

        return $this->etags->applyHeaders(
            Response::success(['redirect' => $public], 'Redirect resolved.'),
            $etag,
            $this->redirectTtl(),
            $this->redirectCacheTag($descriptor, $typeSlug),
        );
    }

    /** @param array<string,mixed> $descriptor */
    private function redirectCacheTag(array $descriptor, string $typeSlug): string
    {
        $entries = isset($descriptor['_target_entry_uuid']) ? [(string) $descriptor['_target_entry_uuid']] : [];
        return $this->etags->cacheTag($entries, $typeSlug);
    }

    /** @param array<string,mixed> $descriptor */
    private function publicRedirectDescriptor(array $descriptor): array
    {
        return array_filter(
            $descriptor,
            static fn (string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function redirectTtl(): int
    {
        return max(0, (int) config($this->context, 'lemma.seo.redirect_ttl', 60));
    }

    /**
     * Resolve references then project each row's schema fields against the schema-derived
     * allow-list. The envelope keys (uuid/version/published_at) are not projectable — only
     * the `fields` sub-object honours `?fields`.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function shape(
        array $rows,
        ContentTypeSchema $schema,
        FieldSelector $selector,
        string $locale,
        string $typeUuid,
        ?array $grantedScopes,
    ): array {
        if ($rows === []) {
            return [];
        }

        if ($this->schemaProjector !== null) {
            foreach ($rows as $i => $row) {
                $rows[$i]['fields'] = $this->schemaProjector->project(
                    $typeUuid,
                    (int) ($row['schema_version'] ?? 0),
                    (array) ($row['fields'] ?? []),
                );
            }
        }

        $rows = $this->references->expand(
            $rows,
            $schema,
            $selector->empty() ? null : $selector,
            $locale,
            2,
            $grantedScopes,
        );

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
        array $typeRow,
    ): Response {
        $versionUuids = array_map(static fn(array $r): string => (string) ($r['version_uuid'] ?? ''), $rows);
        $entryUuids = array_map(static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''), $rows);
        $etag = $this->etags->forList($versionUuids, $this->selectionKey($request));
        $typeSlug = (string) $typeRow['slug'];

        return $this->etags->applyHeaders(
            $response,
            $etag,
            $this->ttl($typeRow),
            $this->etags->cacheTag($entryUuids, $typeSlug),
            $this->isScoped($request),
        );
    }

    /**
     * The request's granted API-key scopes, or null when the request carries no API key
     * (anonymous). Threaded into reference expansion so a referenced non-public type is
     * gated by the same rule as the URL type ({@see DeliveryVisibility}).
     *
     * @return list<string>|null
     */
    private function grantedScopes(Request $request): ?array
    {
        if (!$request->attributes->has('api_key_scopes')) {
            return null;
        }
        return array_values(array_filter(
            (array) $request->attributes->get('api_key_scopes', []),
            'is_string',
        ));
    }

    /**
     * True when the response body depends on an API key's scopes (a private, non-shareable
     * response). Anonymous responses stay publicly cacheable.
     */
    private function isScoped(Request $request): bool
    {
        return $request->attributes->has('api_key_scopes');
    }

    /**
     * @param array<string,mixed> $filter the raw bracket-array filter from the request DTO
     * @return array{sql:string,bindings:list<mixed>}|null
     */
    private function compileFilter(array $filter, ContentTypeSchema $schema, string $locale): ?array
    {
        if ($filter === []) {
            return null;
        }
        return $this->filters->compile($schema, $filter, $locale);
    }

    /** @return array{0:int,1:int} */
    private function pageParams(DeliveryListQuery $query): array
    {
        $page = max(1, $query->page ?? 1);
        $perPage = $query->perPage ?? $this->defaultPerPage();
        return [$page, $this->clampPerPage($perPage)];
    }

    /**
     * The page size for the cursor list path: the requested `perPage` (clamped) or the
     * configured default. Doubles as the "is there a next page?" probe in index(), which asks
     * for exactly this many rows and emits a cursor only when the result is full.
     */
    private function limit(DeliveryListQuery $query): int
    {
        $perPage = $query->perPage ?? $this->defaultPerPage();
        return $this->clampPerPage($perPage);
    }

    /**
     * Clamp a requested page size into the safe range: a non-positive value falls back to the
     * default, and the result is capped at `lemma.delivery.max_per_page` so a client cannot
     * request an unbounded page.
     */
    private function clampPerPage(int $perPage): int
    {
        $max = app($this->context, GeneralSettings::class)->maxPerPage();
        if ($perPage < 1) {
            $perPage = $this->defaultPerPage();
        }
        return min($perPage, $max);
    }

    /** The configured default page size (`lemma.delivery.default_per_page`, fallback 20). */
    private function defaultPerPage(): int
    {
        return app($this->context, GeneralSettings::class)->defaultPerPage();
    }

    /**
     * The Cache-Control max-age (seconds) advertised on delivery responses. A content
     * type's `cache_ttl` overrides the global `lemma.delivery.cache_ttl`; null falls back.
     *
     * @param array<string,mixed> $typeRow
     */
    private function ttl(array $typeRow): int
    {
        if (isset($typeRow['cache_ttl'])) {
            return max(0, (int) $typeRow['cache_ttl']);
        }

        return app($this->context, GeneralSettings::class)->cacheTtl();
    }

    /**
     * The locale to read: the `locale` query param when present and non-empty, otherwise the
     * configured i18n default locale.
     */
    private function locale(?string $locale): string
    {
        if ($locale !== null && $locale !== '') {
            return $locale;
        }
        return $this->locales->default();
    }

    /**
     * Requested locale plus configured i18n fallbacks, de-duplicated and never empty.
     *
     * @return non-empty-list<string>
     */
    private function localeChain(?string $locale): array
    {
        $requested = $this->locale($locale);
        $chain = $this->locales->fallbackChain($requested);
        array_unshift($chain, $requested);
        $out = [];
        foreach ($chain as $locale) {
            $locale = trim((string) $locale);
            if ($locale === '') {
                continue;
            }
            $out[$locale] = $locale;
        }

        return $out === [] ? [$requested] : array_values($out);
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
            'locale=' . $this->locale($this->stringQuery($request, 'locale')),
            'filter=' . json_encode($request->query->all('filter')),
            // Scoped responses can expand references anonymous callers can't see, so the
            // validator MUST differ by access or a shared cache would 304 a scoped
            // conditional request against an anonymous body.
            'scopes=' . $this->scopeFingerprint($request),
        ];
        return implode('&', $parts);
    }

    /**
     * A stable fingerprint of the caller's access for the ETag key: empty for anonymous,
     * else a hash of the sorted granted scopes so two differently-scoped keys (which may
     * expand different references) never share a validator.
     */
    private function scopeFingerprint(Request $request): string
    {
        $scopes = $this->grantedScopes($request);
        if ($scopes === null) {
            return '';
        }
        sort($scopes);
        return sha1(implode(',', $scopes));
    }
}
