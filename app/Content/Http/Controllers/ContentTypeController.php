<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Events\ModelCreated;
use App\Content\Events\ModelUpdated;
use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\SchemaParseException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Glueful\Queue\QueueManager;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeController
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly QueueManager $queue,
        private readonly ?PublishEventEmitter $events = null,
    ) {
    }

    #[ApiOperation(
        summary: 'List content types',
        description: 'Lists every content type (model) defined in this Lemma instance. '
            . 'Requires the `lemma.entries.read` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'All content types.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.read` permission.')]
    public function index(Request $request): Response
    {
        return Response::success(['content_types' => $this->types->all()], 'Content types retrieved.');
    }

    #[ApiOperation(
        summary: 'Create a content type',
        description: 'Defines a new content type (model) with a field schema. The slug must be a unique '
            . 'lowercase identifier. Body: `slug` (required), `name` (required), `description`, '
            . '`schema` (field definitions, each { name, type, required? }). '
            . 'Requires the `lemma.models.manage` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(201, description: 'Content type created.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.models.manage` permission.')]
    #[ApiResponse(422, description: 'Invalid slug/name, duplicate slug, or invalid field schema.')]
    public function store(Request $request): Response
    {
        $in = $this->body($request);
        $slug = (string) ($in['slug'] ?? '');
        $name = (string) ($in['name'] ?? '');
        if (preg_match('/\A[a-z0-9][a-z0-9_-]{0,159}\z/', $slug) !== 1 || trim($name) === '') {
            return Response::validation(['slug' => 'lowercase slug required', 'name' => 'name required']);
        }
        if ($this->types->findBySlug($slug) !== null) {
            return Response::validation(['slug' => "content type '{$slug}' already exists"]);
        }
        try {
            $uuid = $this->types->create([
                'slug' => $slug,
                'name' => trim($name),
                'description' => $in['description'] ?? null,
                'schema' => (array) ($in['schema'] ?? []),
                'created_by' => $this->actor($request),
            ]);
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        $this->ensureFilterIndexes($uuid);
        $this->events?->emitAfterCommit(new ModelCreated(type: $slug, actor: $this->actor($request)));
        return Response::created(['content_type' => $this->types->findByUuid($uuid)], 'Content type created.');
    }

    #[ApiOperation(
        summary: 'Get a content type by slug',
        description: 'Returns one content type (model) by its slug, including its field schema. '
            . 'Requires the `lemma.entries.read` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'The content type.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.read` permission.')]
    #[ApiResponse(404, description: 'No content type with that slug.')]
    public function show(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        return $row === null
            ? Response::notFound('Content type not found.')
            : Response::success(['content_type' => $row], 'Content type retrieved.');
    }

    #[ApiOperation(
        summary: 'Update a content type\'s field schema',
        description: 'Replaces the field schema of an existing content type and bumps its schema version. '
            . 'Enqueues (re)building of filterable-field expression indexes. Body: `schema` (required; '
            . 'replacement field definitions, each { name, type, required?, filterable? }). '
            . 'Requires the `lemma.models.manage` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Schema updated.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.models.manage` permission.')]
    #[ApiResponse(404, description: 'No content type with that slug.')]
    #[ApiResponse(422, description: 'Invalid field schema.')]
    public function updateSchema(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Content type not found.');
        }
        try {
            $this->types->updateSchema($row['uuid'], (array) ($this->body($request)['schema'] ?? []));
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        $this->ensureFilterIndexes($row['uuid']);
        $this->events?->emitAfterCommit(new ModelUpdated(type: (string) $row['slug'], actor: $this->actor($request)));
        return Response::success(['content_type' => $this->types->findByUuid($row['uuid'])], 'Schema updated.');
    }

    /**
     * Enqueue the expression-index reconciliation for a content type's filterable fields.
     * The DDL (CREATE/DROP INDEX CONCURRENTLY) runs out-of-band in the queued job.
     */
    private function ensureFilterIndexes(string $typeUuid): void
    {
        $this->queue->push(EnsureFilterIndexesJob::class, ['content_type_uuid' => $typeUuid]);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
