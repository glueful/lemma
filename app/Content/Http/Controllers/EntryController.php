<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class EntryController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
    ) {
    }

    public function store(Request $request): Response
    {
        $in = $this->body($request);
        $type = $this->types->findBySlug((string) ($in['content_type'] ?? ''));
        if ($type === null) {
            return Response::validation(['content_type' => 'unknown content type']);
        }
        $locale = (string) ($in['locale'] ?? config($this->context, 'lemma.default_locale', 'en'));
        $uuid = $this->entries->createEntry(
            (string) $type['uuid'],
            $locale,
            (int) $type['schema_version'],
            $this->actor($request),
        );
        return Response::created([
            'entry' => $this->entries->findEntry($uuid),
            'draft' => $this->entries->findDraft($uuid, $locale),
        ], 'Entry created.');
    }

    public function show(Request $request, string $uuid): Response
    {
        $entry = $this->entries->findEntry($uuid);
        return $entry === null
            ? Response::notFound('Entry not found.')
            : Response::success(['entry' => $entry], 'Entry retrieved.');
    }

    public function getDraft(Request $request, string $uuid, string $locale): Response
    {
        $draft = $this->entries->findDraft($uuid, $locale);
        return $draft === null
            ? Response::notFound('Draft not found.')
            : Response::success(['draft' => $draft], 'Draft retrieved.');
    }

    public function saveDraft(Request $request, string $uuid, string $locale): Response
    {
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        $in = $this->body($request);
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        try {
            $clean = $this->validator->validate($schema, (array) ($in['fields'] ?? []));
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        }
        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        try {
            $this->entries->saveDraft(
                $uuid,
                $locale,
                $clean,
                (int) $type['schema_version'],
                (int) ($in['lock_version'] ?? -1),
                $this->actor($request),
            );
        } catch (OptimisticLockException) {
            return Response::error('Draft was modified by another writer.', Response::HTTP_CONFLICT, [
                'code' => 'STALE_DRAFT',
                'current' => $this->entries->findDraft($uuid, $locale),
            ]);
        }
        return Response::success(['draft' => $this->entries->findDraft($uuid, $locale)], 'Draft saved.');
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
