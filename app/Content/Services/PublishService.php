<?php

declare(strict_types=1);

namespace App\Content\Services;

use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\VersionRepository;
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
        private readonly ?PublishEventEmitter $events = null,
    ) {
    }

    /**
     * Validate the current draft, snapshot it as the next immutable version, and pin it —
     * all in one transaction (V1_DESIGN §2/§5). Returns the new version uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor): string
    {
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null) {
            throw new \RuntimeException("entry {$entryUuid} not found");
        }
        $draft = $this->entries->findDraft($entryUuid, $locale);
        if ($draft === null) {
            throw new \RuntimeException("no draft for {$entryUuid}/{$locale}");
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        // Throws ValidationException before any write if the draft is invalid.
        $clean = $this->validator->validate($schema, $draft['fields']);

        $version = 0;
        $versionUuid = db($this->context)->transaction(
            function () use ($entryUuid, $locale, $clean, $draft, $actor, &$version): string {
                $version = $this->versions->nextVersionNumber($entryUuid, $locale);
                $versionUuid = $this->versions->appendVersion(
                    $entryUuid,
                    $locale,
                    $version,
                    $clean,
                    (int) $draft['schema_version'],
                    $actor,
                );
                $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
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
        db($this->context)->transaction(function () use ($entryUuid, $locale, $versionUuid, $actor): void {
            $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
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
