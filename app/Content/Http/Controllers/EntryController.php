<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Http\DTOs\CreateEntryData;
use App\Content\Http\DTOs\SaveDraftData;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Support\OptimisticLockException;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiRequestBody;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin API for entries and their working drafts — create an entry, read its identity
 * record, and read/save the per-locale draft. Identity and draft persistence (including the
 * optimistic-lock CAS and the reference projection) live in {@see EntryRepository}; field
 * validation against the content type schema is delegated to {@see FieldValidator}.
 *
 * The interesting path is {@see EntryController::saveDraft()}: it validates first (a
 * {@see ValidationException} → 422) and then catches the repository's
 * {@see OptimisticLockException} to return a 409 with the current draft so the client can
 * rebase. Routes are permission-gated (`lemma.entries.read` / `lemma.entries.write`) by
 * middleware, so authz failures surface as 401/403 before these methods run.
 */
final class EntryController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
    ) {
    }

    /**
     * Create a new entry of the content type named by `content_type` (slug; unknown → 422),
     * seeding an empty draft in the requested locale (defaulting to `lemma.default_locale`).
     * Returns the fresh entry identity record plus the empty draft.
     */
    #[ApiOperation(
        summary: 'Create an entry',
        description: 'Creates a new entry of a content type with an empty draft in the given locale. '
            . 'Body: `content_type` (required; content type slug), `locale` (defaults to '
            . 'lemma.default_locale). Requires the `lemma.entries.write` permission.',
        tags: ['Lemma Admin'],
    )]
    #[ApiRequestBody(schema: CreateEntryData::class)]
    #[ApiResponse(201, description: 'Entry created with an empty draft.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.write` permission.')]
    #[ApiResponse(422, description: 'Unknown content type.')]
    public function store(CreateEntryData $input, Request $request): Response
    {
        // Structural validation (content_type/locale are strings) is done by the hydrated DTO.
        // The content-type-exists check is a domain rule and stays here.
        $type = $this->types->findBySlug($input->content_type);
        if ($type === null) {
            return Response::validation(['content_type' => 'unknown content type']);
        }
        $locale = $input->locale ?? (string) config($this->context, 'lemma.default_locale', 'en');
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

    /**
     * Return the entry's identity/status record (NOT its field content — use getDraft() for
     * field values), or 404 if no entry has that UUID.
     *
     * @param string $uuid Entry UUID
     */
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

    /**
     * Return the current working draft for the entry+locale — field values plus the
     * `lock_version` the client must echo back on save — or 404 if no draft exists for that
     * entry/locale pair.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
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

    /**
     * Validate and persist the draft field values for the entry+locale under optimistic
     * concurrency. The flow is: resolve the entry (404 if gone) → validate the submitted
     * `fields` against the content type schema (a {@see ValidationException} → 422) → call
     * {@see EntryRepository::saveDraft()} passing the client's `lock_version`. A stale
     * lock_version trips the repository's {@see OptimisticLockException}, which is caught here
     * and returned as 409 carrying the current draft (code `STALE_DRAFT`) so the client can
     * rebase and retry. On success the reloaded, version-bumped draft is returned.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
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
    #[ApiRequestBody(schema: SaveDraftData::class)]
    #[ApiResponse(200, description: 'Draft saved.')]
    #[ApiResponse(401, description: 'Missing or invalid authentication.')]
    #[ApiResponse(403, description: 'Principal lacks the `lemma.entries.write` permission.')]
    #[ApiResponse(404, description: 'No entry with that UUID.')]
    #[ApiResponse(409, description: 'Stale lock_version — the draft was modified by another writer.')]
    #[ApiResponse(422, description: 'Field validation failed against the content type schema.')]
    public function saveDraft(SaveDraftData $input, Request $request, string $uuid, string $locale): Response
    {
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        try {
            $clean = $this->validator->validate($schema, $input->fields);
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
                $input->lock_version ?? -1,
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

    /**
     * The authenticated principal's id for audit attribution (created_by / updated_by /
     * event actor), or null when the request carries no resolved {@see UserIdentity}.
     */
    private function actor(Request $request): ?string
    {
        $user = $request->attributes->get('auth.user');
        return $user instanceof UserIdentity ? $user->id() : null;
    }
}
