<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Schema\SchemaParseException;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeController
{
    public function __construct(private readonly ContentTypeRepository $types)
    {
    }

    public function index(Request $request): Response
    {
        return Response::success(['content_types' => $this->types->all()], 'Content types retrieved.');
    }

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
        return Response::created(['content_type' => $this->types->findByUuid($uuid)], 'Content type created.');
    }

    public function show(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        return $row === null
            ? Response::notFound('Content type not found.')
            : Response::success(['content_type' => $row], 'Content type retrieved.');
    }

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
        return Response::success(['content_type' => $this->types->findByUuid($row['uuid'])], 'Schema updated.');
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
