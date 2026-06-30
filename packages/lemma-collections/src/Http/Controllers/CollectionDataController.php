<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Http\Response;
use Glueful\Lemma\Collections\Data\RowRepository;
use Glueful\Lemma\Collections\Data\RowValidator;
use Glueful\Lemma\Collections\Exceptions\InvalidQueryException;
use Glueful\Lemma\Collections\Exceptions\RowNotFoundException;
use Glueful\Lemma\Collections\Exceptions\RowReferencedException;
use Glueful\Lemma\Collections\Exceptions\RowValidationException;
use Glueful\Lemma\Collections\Http\ActorResolver;
use Glueful\Lemma\Collections\Query\QueryCompiler;
use Glueful\Lemma\Collections\Relations\RelationResolver;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public CRUD + query controller for developer-defined collection data.
 *
 * Accessible at /v1/collections/{name} and /v1/collections/{name}/{uuid}.
 * Every route runs through the optional_api_key middleware followed by
 * CollectionScopeMiddleware (default-deny, requires a scoped API key).
 *
 * ## Exception → HTTP status mapping
 *
 *   RowValidationException  → 422  (field-level errors in the error.details payload)
 *   InvalidQueryException   → 422  (bad filter/sort/fields param)
 *   RowNotFoundException    → 404
 *   RowReferencedException  → 409  (restrict-delete: row is still referenced)
 */
final class CollectionDataController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly CollectionDefinitionRepository $definitions,
        private readonly RowRepository $rows,
        private readonly RowValidator $validator,
        private readonly QueryCompiler $compiler,
        private readonly RelationResolver $relations,
        private readonly ActorResolver $actor,
    ) {
    }

    /**
     * List rows in the collection.
     *
     * Accepts query params:
     *   filter[field][op]=value   — field filtering
     *   sort=field,-other         — comma-separated; '-' prefix = DESC
     *   fields=col1,col2          — column projection; uuid always included
     *   page, perPage             — offset pagination (defaults from config)
     *   expand=field1,field2      — inline-expand relation fields
     *
     * @param string $name Collection slug
     */
    #[ApiOperation(summary: 'List rows in a collection', tags: ['Collections'])]
    #[ApiResponse(200, description: 'Paginated rows.')]
    public function list(Request $request, string $name): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        try {
            $result = $this->compiler->list($def, $this->listParams($request));
        } catch (InvalidQueryException $e) {
            return Response::validation(['query' => $e->getMessage()]);
        }

        $rows = $result->data;

        $expand = $this->expandParam($request);
        if ($expand !== []) {
            $rows = $this->relations->expand($def, $rows, $expand);
        }

        return Response::paginated(
            $rows,
            $result->total,
            $result->page,
            $result->perPage,
        );
    }

    /**
     * Return one row by UUID.
     *
     * @param string $name Collection slug
     * @param string $uuid Row UUID
     */
    #[ApiOperation(summary: 'Get a row by UUID', tags: ['Collections'])]
    #[ApiResponse(200, description: 'The row.')]
    public function show(Request $request, string $name, string $uuid): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        try {
            $row = $this->rows->find($def, $uuid);
        } catch (RowNotFoundException $e) {
            return Response::notFound($e->getMessage());
        }

        $expand = $this->expandParam($request);
        if ($expand !== []) {
            $expanded = $this->relations->expand($def, [$row], $expand);
            $row      = $expanded[0] ?? $row;
        }

        return Response::success($row, 'Row retrieved.');
    }

    /**
     * Create a single row.
     *
     * @param string $name Collection slug
     */
    #[ApiOperation(summary: 'Create a row', tags: ['Collections'])]
    #[ApiResponse(201, description: 'Row created.')]
    public function create(Request $request, string $name): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        $input = $this->body($request);
        $actor = $this->actor->resolve($request);

        try {
            $row = $this->rows->create($def, $input, $actor);
        } catch (RowValidationException $e) {
            return Response::validation($e->errors());
        }

        return Response::created($row, 'Row created.');
    }

    /**
     * Bulk-create rows — strict all-or-nothing.
     *
     * Expects: { "rows": [ {...}, {...}, ... ] }
     *
     * Validates every row before inserting any. If any row fails validation the
     * entire request is rejected with 422 and per-row errors; zero rows are inserted.
     * Valid batches are inserted in a single database transaction.
     *
     * @param string $name Collection slug
     */
    #[ApiOperation(summary: 'Bulk-create rows (all-or-nothing)', tags: ['Collections'])]
    #[ApiResponse(201, description: 'Rows created.')]
    public function bulkCreate(Request $request, string $name): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        $body = $this->body($request);
        $rows = isset($body['rows']) && is_array($body['rows']) ? array_values($body['rows']) : [];

        if ($rows === []) {
            return Response::validation(['rows' => 'rows must be a non-empty array.']);
        }

        $maxBulk = (int) config($this->context, 'lemma.collections.max_bulk', 100);
        if (count($rows) > $maxBulk) {
            return Response::validation(['rows' => "Bulk create is limited to {$maxBulk} rows per request."]);
        }

        // Phase 1: validate every row — no inserts yet.
        $perRowErrors = [];
        foreach ($rows as $i => $row) {
            try {
                $this->validator->validate($def, (array) $row, false);
            } catch (RowValidationException $e) {
                $perRowErrors[$i] = $e->errors();
            }
        }

        if ($perRowErrors !== []) {
            return Response::validation(['rows' => $perRowErrors]);
        }

        // Phase 2: all valid — insert in one transaction.
        $actor   = $this->actor->resolve($request);
        $created = [];

        try {
            $this->connection->transaction(function () use ($def, $rows, $actor, &$created): void {
                foreach ($rows as $row) {
                    $created[] = $this->rows->create($def, (array) $row, $actor);
                }
            });
        } catch (RowValidationException $e) {
            // Relation-target check can throw RowValidationException inside the transaction.
            return Response::validation($e->errors());
        } catch (RowNotFoundException $e) {
            // A relation target can vanish between Phase 1 validation and Phase 2 insert under a
            // concurrent delete; the transaction rolls back — surface it as 404, not an unhandled 500.
            return Response::notFound($e->getMessage());
        }

        return Response::created($created, 'Rows created.');
    }

    /**
     * Partially update a row.
     *
     * @param string $name Collection slug
     * @param string $uuid Row UUID
     */
    #[ApiOperation(summary: 'Update a row', tags: ['Collections'])]
    #[ApiResponse(200, description: 'Row updated.')]
    public function update(Request $request, string $name, string $uuid): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        $input = $this->body($request);
        $actor = $this->actor->resolve($request);

        try {
            $row = $this->rows->update($def, $uuid, $input, $actor);
        } catch (RowNotFoundException $e) {
            return Response::notFound($e->getMessage());
        } catch (RowValidationException $e) {
            return Response::validation($e->errors());
        }

        return Response::success($row, 'Row updated.');
    }

    /**
     * Delete a row by UUID.
     *
     * @param string $name Collection slug
     * @param string $uuid Row UUID
     */
    #[ApiOperation(summary: 'Delete a row', tags: ['Collections'])]
    #[ApiResponse(204, description: 'Row deleted.')]
    public function delete(Request $request, string $name, string $uuid): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        try {
            $this->rows->delete($def, $uuid, $this->actor->resolve($request));
        } catch (RowNotFoundException $e) {
            return Response::notFound($e->getMessage());
        } catch (RowReferencedException $e) {
            return Response::error($e->getMessage(), 409);
        }

        return Response::noContent();
    }

    /** DELETE /collections/{name}/rows — delete every row (keeps the schema). */
    #[ApiOperation(summary: 'Delete all rows in a collection', tags: ['Collections'])]
    #[ApiResponse(200, description: 'Rows deleted.')]
    public function truncate(Request $request, string $name): Response
    {
        $def = $this->definitions->findByName($name);
        if ($def === null) {
            return Response::notFound("Collection '{$name}' not found.");
        }

        $deleted = $this->rows->truncate($def);

        return Response::success(['deleted' => $deleted], "Cleared {$deleted} row(s) from '{$name}'.");
    }

    // ── private helpers ───────────────────────────────────────────────────────

    /**
     * Decode the JSON request body; returns [] on empty or non-JSON.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Collect list query params from the request.
     *
     * @return array<string, mixed>
     */
    private function listParams(Request $request): array
    {
        return [
            'filter'  => $request->query->all('filter'),
            'sort'    => (string) $request->query->get('sort', ''),
            'fields'  => (string) $request->query->get('fields', ''),
            'page'    => (int) $request->query->get('page', '1'),
            'perPage' => $request->query->has('perPage')
                ? (int) $request->query->get('perPage')
                : null,
        ];
    }

    /**
     * Parse the ?expand query param into a list of field names to inline-expand.
     *
     * @return list<string>
     */
    private function expandParam(Request $request): array
    {
        $raw = (string) $request->query->get('expand', '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
