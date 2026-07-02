<?php

declare(strict_types=1);

namespace App\Content\Services;

use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;

final class PublishService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly VersionRepository $versions,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
        private readonly ReferenceProjectionRepository $references,
        private readonly ?PublishEventEmitter $events = null,
        private readonly ?SchemaProjector $projector = null,
    ) {
    }

    /**
     * Validate the current draft, snapshot it as the next immutable version, and pin it —
     * all in one transaction (V1_DESIGN §2/§5). Returns the new version uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor): string
    {
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            // A soft-deleted entry keeps its draft/version rows, so publishing would mint a new
            // immutable version and re-pin content that is supposed to be gone. Guard here so both
            // the HTTP path and any other caller share the check (ScheduleRunner already guards).
            // The RuntimeException maps to a 404 in PublicationController.
            throw new \RuntimeException("entry {$entryUuid} not found");
        }
        $draft = $this->entries->findDraft($entryUuid, $locale);
        if ($draft === null) {
            throw new \RuntimeException("no draft for {$entryUuid}/{$locale}");
        }
        $typeUuid = (string) $entry['content_type_uuid'];
        $schema = $this->types->schemaFor($typeUuid);

        // Project a draft still on an OLDER schema up to the current shape before validating, so a
        // draft behind a lagging/failed backfill (e.g. a renamed field) doesn't silently lose the
        // renamed data — FieldValidator only keeps keys the current schema declares. A draft already
        // at the current version is untouched (no projection, same stored version) → behaviour
        // unchanged for the normal path. The snapshot then records the CURRENT version, so delivery
        // read-projection stays a no-op instead of double-projecting.
        $fields = $draft['fields'];
        $storeVersion = (int) $draft['schema_version'];
        if ($this->projector !== null) {
            $typeRow = $this->types->findByUuid($typeUuid);
            $currentVersion = $typeRow === null ? $storeVersion : (int) $typeRow['schema_version'];
            if ($storeVersion < $currentVersion) {
                $fields = $this->projector->project($typeUuid, $storeVersion, $fields);
                $storeVersion = $currentVersion;
            }
        }

        // Throws ValidationException before any write if the draft is invalid. Publish is the strict
        // gate: unlike draft saves, a present-but-empty required field or a dangling reference is
        // rejected here so invalid content can't go live (draft saves stay permissive).
        $clean = $this->validator->validate($schema, $fields, true);

        $version = 0;
        $versionUuid = db($this->context)->transaction(
            function () use ($entryUuid, $locale, $clean, $storeVersion, $actor, $schema, &$version): string {
                $version = $this->versions->reserveNextVersionNumber($entryUuid, $locale);
                $versionUuid = $this->versions->appendVersion(
                    $entryUuid,
                    $locale,
                    $version,
                    $clean,
                    $storeVersion,
                    $actor,
                );
                $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
                $this->references->rebuildForEntry($entryUuid, $schema, $clean, $locale);
                return $versionUuid;
            }
        );

        // Primary domain event, dispatched on the OUTERMOST commit only. If publish()
        // owns the outermost transaction the commit already happened, so afterCommit
        // dispatches immediately; if an outer transaction is still active the dispatch
        // is bound to it and fires (or is discarded) with that outer commit/rollback.
        $this->events?->emitAfterCommit(new EntryPublished(
            entry: $entryUuid,
            type: (string) $entry['content_type_uuid'],
            locale: $locale,
            version: $version,
            actor: $actor,
        ));

        return $versionUuid;
    }

    public function unpublish(string $entryUuid, string $locale): void
    {
        $entry = $this->entries->findEntry($entryUuid);
        db($this->context)->transaction(function () use ($entryUuid, $locale): void {
            $this->versions->unpin($entryUuid, $locale);
            $this->references->clearForEntryLocale($entryUuid, $locale);
        });
        $this->events?->emitAfterCommit(new EntryUnpublished(
            entry: $entryUuid,
            type: $entry === null ? '' : (string) $entry['content_type_uuid'],
            locale: $locale,
            actor: null,
        ));
    }

    /** Re-pin an existing (older) version without writing a new one. */
    public function rollback(string $entryUuid, string $locale, string $versionUuid, ?string $actor): void
    {
        $version = $this->versions->findVersionByUuid($versionUuid);
        if (
            $version === null
            || (string) $version['entry_uuid'] !== $entryUuid
            || (string) $version['locale'] !== $locale
        ) {
            throw new \RuntimeException('version does not belong to this entry/locale');
        }
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry !== null && ($entry['status'] ?? null) === 'deleted') {
            // Same rule as publish(): don't re-pin a version onto a soft-deleted entry. Mapped to a
            // 422 in PublicationController::rollback.
            throw new \RuntimeException('entry has been deleted');
        }
        $schema = $entry === null
            ? null
            : $this->types->schemaFor((string) $entry['content_type_uuid']);
        db($this->context)->transaction(function () use (
            $entryUuid,
            $locale,
            $versionUuid,
            $actor,
            $schema,
            $version,
            $entry
        ): void {
            $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
            if ($schema !== null) {
                $fields = (array) $version['fields'];
                if ($this->projector !== null && $entry !== null) {
                    $fields = $this->projector->project(
                        (string) $entry['content_type_uuid'],
                        (int) ($version['schema_version'] ?? 0),
                        $fields,
                    );
                }
                $this->references->rebuildForEntry($entryUuid, $schema, $fields, $locale);
            }
        });
        // Re-publishing a prior version is a publish for downstream consumers (V1_DESIGN §5).
        $this->events?->emitAfterCommit(new EntryPublished(
            entry: $entryUuid,
            type: $entry === null ? '' : (string) $entry['content_type_uuid'],
            locale: $locale,
            version: isset($version['version']) ? (int) $version['version'] : null,
            actor: $actor,
        ));
    }
}
