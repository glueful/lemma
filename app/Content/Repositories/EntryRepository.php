<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Events\AssetAttached;
use App\Content\Events\AssetDetached;
use App\Content\Events\EntryCreated;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryUpdated;
use App\Content\Localization\LocaleFieldSeeder;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Support\OptimisticLockException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class EntryRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApplicationContext $context,
        private readonly ContentTypeRepository $types,
        private readonly ?PublishEventEmitter $events = null,
        private readonly LocaleFieldSeeder $seeder = new LocaleFieldSeeder(),
    ) {
    }

    /** Create entry identity + an empty draft for the locale. Returns entry uuid. */
    public function createEntry(string $contentTypeUuid, string $locale, int $schemaVersion, ?string $actor): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entries')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => $contentTypeUuid,
            'status' => 'active',
            'created_by' => $actor,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        $this->db->table('entry_drafts')->insert([
            'entry_uuid' => $uuid,
            'locale' => $locale,
            'fields' => json_encode([], JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'lock_version' => 0,
            'updated_by' => $actor,
            'updated_at' => $this->now(),
        ]);
        // No surrounding transaction here, so afterCommit dispatches immediately —
        // unless an outer transaction is active, in which case it binds to that commit.
        $this->events?->emitAfterCommit(new EntryCreated(
            entry: $uuid,
            type: $contentTypeUuid,
            locale: $locale,
            version: null,
            actor: $actor,
        ));
        return $uuid;
    }

    /**
     * Save the draft working copy under optimistic concurrency. The caller passes the
     * lock_version it last read; if the row has moved on, throw (controller -> 409).
     *
     * The optimistic-lock CAS runs inside one transaction (V1_DESIGN §4): a stale save
     * (0 affected) throws OptimisticLockException and rolls back. Reference projection is
     * intentionally not touched here because it indexes published versions, not drafts.
     *
     * @param array<string,mixed> $fields already-validated, cleaned payload
     */
    public function saveDraft(
        string $entryUuid,
        string $locale,
        array $fields,
        int $schemaVersion,
        int $expectedLockVersion,
        ?string $actor,
    ): void {
        // Capture the PRIOR persisted draft's asset-field targets BEFORE the write, so we
        // can diff old-vs-new after a successful commit (V1_DESIGN §8 "where is this asset
        // used"). Read here, before the CAS overwrites the row; the diff/emit happens only
        // on the success path below, so a stale-lock 409 (which throws inside the
        // transaction) emits nothing.
        $oldFields = $this->draftFields($entryUuid, $locale);
        $oldAssets = $this->assetTargets($entryUuid, $oldFields);
        $changed = $oldFields != $fields;

        db($this->context)->transaction(function () use (
            $entryUuid,
            $locale,
            $fields,
            $schemaVersion,
            $expectedLockVersion,
            $actor,
        ): void {
            $affected = $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entryUuid)
                ->where('locale', '=', $locale)
                ->where('lock_version', '=', $expectedLockVersion)
                ->update([
                    'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                    'schema_version' => $schemaVersion,
                    'lock_version' => $expectedLockVersion + 1,
                    'updated_by' => $actor,
                    'updated_at' => $this->now(),
                ]);
            if ($affected < 1) {
                // Stale save: throw inside the transaction so it rolls back before any
                // projection write. Controller maps OptimisticLockException -> 409.
                throw new OptimisticLockException();
            }

            // Reference projection is a published-content index. Draft saves deliberately
            // do not update it; publish rebuilds it from the immutable version snapshot.
        });

        // Reached only if the CAS succeeded (a stale save throws inside the transaction
        // above and never gets here, so no event fires on the 409 path). Emit the primary
        // update only for a content change; a client retry that writes identical fields may
        // advance the optimistic lock, but downstream consumers do not need reprocessing.
        $entry = $this->findEntry($entryUuid);
        if ($changed) {
            $this->events?->emitAfterCommit(new EntryUpdated(
                entry: $entryUuid,
                type: $entry === null ? '' : (string) $entry['content_type_uuid'],
                locale: $locale,
                version: $expectedLockVersion + 1,
                actor: $actor,
            ));
        }

        // ADDITIVE asset-delta events (V1_DESIGN §8): diff the prior draft's asset-field
        // targets against the new ones and emit one event per changed blob. These are
        // ADDITIONAL to — and do not affect — the single primary EntryUpdated above. They
        // emit after the transaction returns (only reached on success), so a 409 discards
        // them along with the primary event; on success they all fire post-commit.
        $newAssets = $this->assetTargets($entryUuid, $fields);
        foreach (array_diff($newAssets, $oldAssets) as $blob) {
            $this->events?->emitAfterCommit(new AssetAttached(
                asset: $blob,
                entry: $entryUuid,
                actor: $actor,
            ));
        }
        foreach (array_diff($oldAssets, $newAssets) as $blob) {
            $this->events?->emitAfterCommit(new AssetDetached(
                asset: $blob,
                entry: $entryUuid,
                actor: $actor,
            ));
        }
    }

    /**
     * The deduped set of blob uuids referenced by the entry's asset-type fields in the
     * given draft fields. Asset-type fields are resolved from the content type schema;
     * each value is normalized to a list of uuids via the same logic the reference
     * projection uses, so asset-target parsing stays identical across both.
     *
     * @param array<string,mixed> $fields
     * @return list<string>
     */
    private function assetTargets(string $entryUuid, array $fields): array
    {
        $entry = $this->findEntry($entryUuid);
        $type = $entry === null
            ? null
            : $this->types->findByUuid((string) $entry['content_type_uuid']);
        $schema = $type === null
            ? ContentTypeSchema::fromArray([])
            : ContentTypeSchema::fromArray($type['schema']);

        $targets = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'asset') {
                continue;
            }
            foreach (ReferenceProjectionRepository::targets($fields[$f->name] ?? null) as $blob) {
                $targets[$blob] = true;
            }
        }
        return array_keys($targets);
    }

    /**
     * The prior persisted draft's raw fields (empty array if the draft does not yet exist).
     *
     * @return array<string,mixed>
     */
    private function draftFields(string $entryUuid, string $locale): array
    {
        $draft = $this->findDraft($entryUuid, $locale);
        return $draft === null ? [] : (array) $draft['fields'];
    }

    /** @return array<string,mixed>|null */
    public function findEntry(string $uuid): ?array
    {
        return $this->db->table('entries')->where('uuid', '=', $uuid)->first() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findDraft(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($row === null) {
            return null;
        }
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['lock_version'] = (int) $row['lock_version'];
        $row['schema_version'] = (int) $row['schema_version'];
        return $row;
    }

    /**
     * Create a draft row for a locale that does not yet have one. When a source locale
     * is supplied, copy its current draft fields; otherwise create an empty working copy.
     *
     * @return array<string,mixed>
     */
    public function createLocaleDraft(
        string $entryUuid,
        string $locale,
        int $schemaVersion,
        ?string $actor,
        ?string $sourceLocale = null,
        bool $overwrite = false,
        ?ContentTypeSchema $schema = null,
    ): array {
        $existing = $this->findDraft($entryUuid, $locale);
        if ($existing !== null && !$overwrite) {
            throw new \RuntimeException('Draft already exists for locale.');
        }

        $fields = [];
        if ($sourceLocale !== null) {
            $source = $this->findDraft($entryUuid, $sourceLocale);
            if ($source === null) {
                throw new \InvalidArgumentException('Source draft not found.');
            }
            $fields = $schema === null
                ? (array) $source['fields']
                : $this->seeder->seed((array) $source['fields'], $schema);
        }

        $data = [
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'lock_version' => 0,
            'updated_by' => $actor,
            'updated_at' => $this->now(),
        ];

        if ($existing === null) {
            $this->db->table('entry_drafts')->insert($data);
        } else {
            $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entryUuid)
                ->where('locale', '=', $locale)
                ->update($data);
        }

        return $this->findDraft($entryUuid, $locale) ?? [];
    }

    /**
     * Summarize the localized working/publication state for an entry, sorted by locale.
     *
     * @return list<array{
     *   locale:string,
     *   has_draft:bool,
     *   is_published:bool,
     *   route_slug:?string,
     *   draft_updated_at:?string,
     *   published_at:?string,
     *   scheduled:array{publish:?string,unpublish:?string,last_failure:?array}
     * }>
     */
    public function localeSummary(string $entryUuid): array
    {
        $locales = [];

        foreach ($this->db->table('entry_drafts')->where('entry_uuid', '=', $entryUuid)->get() as $row) {
            $locale = (string) $row['locale'];
            $locales[$locale] ??= [
                'locale' => $locale,
                'has_draft' => false,
                'is_published' => false,
                'route_slug' => null,
                'draft_updated_at' => null,
                'published_at' => null,
                'scheduled' => $this->emptyScheduleSummary(),
            ];
            $locales[$locale]['has_draft'] = true;
            $locales[$locale]['draft_updated_at'] = $row['updated_at'] ?? null;
        }

        foreach ($this->db->table('entry_publications')->where('entry_uuid', '=', $entryUuid)->get() as $row) {
            $locale = (string) $row['locale'];
            $locales[$locale] ??= [
                'locale' => $locale,
                'has_draft' => false,
                'is_published' => false,
                'route_slug' => null,
                'draft_updated_at' => null,
                'published_at' => null,
                'scheduled' => $this->emptyScheduleSummary(),
            ];
            $locales[$locale]['is_published'] = true;
            $locales[$locale]['published_at'] = $row['published_at'] ?? null;
        }

        foreach ($this->db->table('entry_routes')->where('entry_uuid', '=', $entryUuid)->get() as $row) {
            $locale = (string) $row['locale'];
            $locales[$locale] ??= [
                'locale' => $locale,
                'has_draft' => false,
                'is_published' => false,
                'route_slug' => null,
                'draft_updated_at' => null,
                'published_at' => null,
                'scheduled' => $this->emptyScheduleSummary(),
            ];
            $locales[$locale]['route_slug'] = $row['slug'] ?? null;
        }

        foreach ($this->db->table('entry_schedules')->where('entry_uuid', '=', $entryUuid)->get() as $row) {
            $locale = (string) $row['locale'];
            $locales[$locale] ??= [
                'locale' => $locale,
                'has_draft' => false,
                'is_published' => false,
                'route_slug' => null,
                'draft_updated_at' => null,
                'published_at' => null,
                'scheduled' => $this->emptyScheduleSummary(),
            ];

            if (($row['status'] ?? null) === 'pending') {
                $action = (string) $row['action'];
                if ($action === 'publish' || $action === 'unpublish') {
                    $locales[$locale]['scheduled'][$action] = $this->isoUtc($row['run_at'] ?? null);
                }
            }

            if (($row['status'] ?? null) === 'failed' && $row['failure_reason'] !== null) {
                $locales[$locale]['scheduled']['last_failure'] ??= [
                    'action' => (string) $row['action'],
                    'run_at' => $this->isoUtc($row['run_at'] ?? null),
                    'reason' => (string) $row['failure_reason'],
                ];
            }
        }

        ksort($locales);
        return array_values($locales);
    }

    /**
     * @return array{publish:?string,unpublish:?string,last_failure:?array}
     */
    private function emptyScheduleSummary(): array
    {
        return ['publish' => null, 'unpublish' => null, 'last_failure' => null];
    }

    public function softDelete(string $uuid): void
    {
        $entry = $this->findEntry($uuid);
        $assets = $this->draftAssetTargetsForEntry($uuid);

        $this->db->table('entries')->where('uuid', '=', $uuid)
            ->update(['status' => 'deleted', 'updated_at' => $this->now()]);
        (new ReferenceProjectionRepository($this->db))->clearForEntry($uuid);

        foreach ($assets as $blob) {
            $this->events?->emitAfterCommit(new AssetDetached(
                asset: $blob,
                entry: $uuid,
                actor: null,
            ));
        }

        $this->events?->emitAfterCommit(new EntryDeleted(
            entry: $uuid,
            type: $entry === null ? '' : (string) $entry['content_type_uuid'],
            locale: null,
            version: null,
            actor: null,
        ));
    }

    /**
     * @return list<string>
     */
    private function draftAssetTargetsForEntry(string $entryUuid): array
    {
        $rows = $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $entryUuid)
            ->get();

        $targets = [];
        foreach ($rows as $row) {
            $raw = $row['fields'] ?? [];
            $fields = is_string($raw)
                ? (json_decode($raw, true) ?: [])
                : (array) $raw;

            foreach ($this->assetTargets($entryUuid, $fields) as $blob) {
                $targets[$blob] = true;
            }
        }

        return array_keys($targets);
    }

    public function discardDraft(string $entryUuid, string $locale): void
    {
        $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->delete();
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function isoUtc(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Exception) {
            return (string) $value;
        }
    }
}
