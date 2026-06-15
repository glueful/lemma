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
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
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

    #[ApiOperation(
        summary: 'Create an entry',
        description: 'Creates a new entry of a content type with an empty draft in the given locale. '
            . 'Body: `content_type` (required; content type slug), `locale` (defaults to '
            . 'lemma.default_locale). Requires the `lemma.entries.write` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(201, description: 'Entry created with an empty draft.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.write` permission.')]
    #[ApiResponse(422, description: 'Unknown content type.')]
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

    #[ApiOperation(
        summary: 'Get an entry',
        description: 'Returns the entry record (identity + status), not its field content. '
            . 'Requires the `lemma.entries.read` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'The entry.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.read` permission.')]
    #[ApiResponse(404, description: 'No entry with that UUID.')]
    public function show(Request $request, string $uuid): Response
    {
        $entry = $this->entries->findEntry($uuid);
        return $entry === null
            ? Response::notFound('Entry not found.')
            : Response::success(['entry' => $entry], 'Entry retrieved.');
    }

    #[ApiOperation(
        summary: 'Get an entry\'s draft for a locale',
        description: 'Returns the current working draft (field values + optimistic-lock version) for the '
            . 'entry in the given locale. Requires the `lemma.entries.read` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'The draft.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.read` permission.')]
    #[ApiResponse(404, description: 'No draft for that entry/locale.')]
    public function getDraft(Request $request, string $uuid, string $locale): Response
    {
        $draft = $this->entries->findDraft($uuid, $locale);
        return $draft === null
            ? Response::notFound('Draft not found.')
            : Response::success(['draft' => $draft], 'Draft retrieved.');
    }

    #[ApiOperation(
        summary: 'Save an entry\'s draft (optimistic-locked)',
        description: 'Validates and stores the entry\'s draft field values for the locale. Pass the '
            . '`lock_version` returned by the last read; a stale value yields 409 Conflict with the '
            . 'current draft so the client can rebase. Field values are validated against the content '
            . 'type schema. Body: `fields` (required; field values keyed by field name), `lock_version` '
            . '(required; optimistic-lock counter from the last read). '
            . 'Requires the `lemma.entries.write` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiResponse(200, description: 'Draft saved.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.write` permission.')]
    #[ApiResponse(404, description: 'No entry with that UUID.')]
    #[ApiResponse(409, description: 'Stale lock_version — the draft was modified by another writer.')]
    #[ApiResponse(422, description: 'Field validation failed against the content type schema.')]
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
