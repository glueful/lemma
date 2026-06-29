<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Lemma\Collections\CollectionManager;
use Glueful\Lemma\Collections\Exceptions\BlockedSchemaChangeException;
use Glueful\Lemma\Collections\Exceptions\CollectionValidationException;
use Glueful\Lemma\Collections\Exceptions\DestructiveConfirmationRequiredException;
use Glueful\Lemma\Collections\Http\ActorResolver;
use Glueful\Lemma\Collections\Http\DTOs\AddIndexData;
use Glueful\Lemma\Collections\Http\DTOs\CreateCollectionData;
use Glueful\Lemma\Collections\Http\DTOs\FieldData;
use Glueful\Lemma\Collections\Http\DTOs\UpdateAccessData;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Lemma\Collections\Schema\AccessPolicy;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin schema-management API for collections (models): list/show, create, add/drop fields, add/drop
 * indexes, replace the access policy, and drop a collection.
 *
 * A thin HTTP layer over {@see CollectionManager}; the actor is resolved from the request and stamped
 * on every structural change. Domain failures map to HTTP: CollectionValidationException and
 * BlockedSchemaChangeException → 422, DestructiveConfirmationRequiredException → 409, an unknown
 * collection (\DomainException from the manager) → 404.
 */
final class CollectionAdminSchemaController
{
    public function __construct(
        private readonly CollectionManager $manager,
        private readonly CollectionDefinitionRepository $collections,
        private readonly ActorResolver $actors,
    ) {
    }

    public function index(Request $request): Response
    {
        return Response::success(['collections' => $this->collections->all()], 'Collections retrieved.');
    }

    public function show(Request $request, string $name): Response
    {
        $definition = $this->collections->findByName($name);

        return $definition === null
            ? Response::notFound("Collection '{$name}' not found.")
            : Response::success(['collection' => $definition], 'Collection retrieved.');
    }

    public function store(CreateCollectionData $data, Request $request): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->create($data->toPayload(), $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::created(['collection' => $definition], "Collection '{$definition->name}' created.");
    }

    public function addField(FieldData $data, Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->addField($name, $data->toArray(), $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Field added.');
    }

    public function dropField(Request $request, string $name, string $field): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->dropField(
                $name,
                $field,
                ['confirm' => $this->confirm($request)],
                $actor->type,
                $actor->id,
            );
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Field dropped.');
    }

    public function addIndex(AddIndexData $data, Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->addIndex($name, $data->field, $data->toSettings(), $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Index added.');
    }

    public function dropIndex(Request $request, string $name, string $field): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $definition = $this->manager->removeIndex($name, $field, $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Index removed.');
    }

    public function updateAccess(UpdateAccessData $data, Request $request, string $name): Response
    {
        try {
            $definition = $this->manager->setAccessPolicy($name, AccessPolicy::fromArray($data->toArray()));
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success(['collection' => $definition], 'Access policy updated.');
    }

    public function destroy(Request $request, string $name): Response
    {
        $actor = $this->actors->resolve($request);
        try {
            $this->manager->dropCollection($name, ['confirm' => $this->confirm($request)], $actor->type, $actor->id);
        } catch (\Throwable $e) {
            return $this->mapException($e);
        }

        return Response::success([], "Collection '{$name}' deleted.");
    }

    /** The optional `confirm` body field forwarded to guarded-drop operations. */
    private function confirm(Request $request): ?string
    {
        $content = $request->getContent();
        if (!is_string($content) || $content === '') {
            return null;
        }
        $decoded = json_decode($content, true);
        $confirm = is_array($decoded) ? ($decoded['confirm'] ?? null) : null;

        return is_string($confirm) ? $confirm : null;
    }

    private function mapException(\Throwable $e): Response
    {
        return match (true) {
            $e instanceof DestructiveConfirmationRequiredException => Response::error($e->getMessage(), 409),
            $e instanceof CollectionValidationException => Response::validation($e->errors()),
            $e instanceof BlockedSchemaChangeException => Response::validation(['schema' => $e->getMessage()]),
            $e instanceof \DomainException => Response::notFound($e->getMessage()),
            default => throw $e,
        };
    }
}
