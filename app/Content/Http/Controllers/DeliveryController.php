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
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
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

    private function limit(Request $request): int
    {
        $perPage = $request->query->has('perPage')
            ? (int) $request->query->get('perPage')
            : $this->defaultPerPage();
        return $this->clampPerPage($perPage);
    }

    private function clampPerPage(int $perPage): int
    {
        $max = (int) config($this->context, 'lemma.delivery.max_per_page', 100);
        if ($perPage < 1) {
            $perPage = $this->defaultPerPage();
        }
        return min($perPage, $max);
    }

    private function defaultPerPage(): int
    {
        return (int) config($this->context, 'lemma.delivery.default_per_page', 20);
    }

    private function ttl(): int
    {
        return (int) config($this->context, 'lemma.delivery.cache_ttl', 60);
    }

    private function locale(Request $request): string
    {
        $locale = $this->stringQuery($request, 'locale');
        if ($locale !== null && $locale !== '') {
            return $locale;
        }
        return (string) config($this->context, 'lemma.default_locale', 'en');
    }

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

    private function looksLikeNanoid(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{12}\z/', $value) === 1;
    }
}
