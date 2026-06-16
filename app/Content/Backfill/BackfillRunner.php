<?php

declare(strict_types=1);

namespace App\Content\Backfill;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\Migration\MigrationOpSet;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;
use Psr\Container\ContainerInterface;

final class BackfillRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly MigrationRepository $migrations,
        private readonly ContentTypeRepository $types,
        private readonly VersionRepository $versions,
        private readonly ReferenceProjectionRepository $references,
        private readonly QueueManager $queue,
        private readonly ContainerInterface $container,
    ) {
    }

    /** @return array{done:int,failed:int} */
    public function run(string $migrationUuid): array
    {
        $migration = $this->migrations->find($migrationUuid);
        if ($migration === null) {
            throw new \RuntimeException("migration {$migrationUuid} not found");
        }

        $typeUuid = (string) $migration['content_type_uuid'];
        $toVersion = (int) $migration['to_version'];
        $opSet = MigrationOpSet::fromArray($migration['ops']);
        $schema = $this->types->schemaFor($typeUuid);
        $actor = $migration['created_by'] === null ? null : (string) $migration['created_by'];

        $this->migrations->resetFailures($migrationUuid);

        foreach ($this->draftItems($typeUuid, $toVersion) as $item) {
            $this->processDraft($migrationUuid, $opSet, $toVersion, $item);
        }
        foreach ($this->publishedItems($typeUuid, $toVersion) as $item) {
            $this->processPublished($migrationUuid, $opSet, $schema, $toVersion, $actor, $item);
        }

        $this->queue->push(EnsureFilterIndexesJob::class, ['content_type_uuid' => $typeUuid]);
        $this->invalidateCache($typeUuid);

        $remaining = count($this->draftItems($typeUuid, $toVersion))
            + count($this->publishedItems($typeUuid, $toVersion));
        $this->migrations->finish($migrationUuid, $remaining === 0 ? 'completed' : 'failed');

        $row = $this->migrations->find($migrationUuid);
        return [
            'done' => (int) ($row['work_items_done'] ?? 0),
            'failed' => (int) ($row['work_items_failed'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function processDraft(string $migrationUuid, MigrationOpSet $opSet, int $toVersion, array $item): void
    {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];

        try {
            $fields = $this->decodeFields($item['fields'] ?? []);
            $migrated = $opSet->apply($fields);
            $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entry)
                ->where('locale', '=', $locale)
                ->update([
                    'fields' => json_encode($migrated, JSON_THROW_ON_ERROR),
                    'schema_version' => $toVersion,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $this->migrations->incrementDone($migrationUuid);
        } catch (\Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'draft', $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $item
     */
    private function processPublished(
        string $migrationUuid,
        MigrationOpSet $opSet,
        ContentTypeSchema $schema,
        int $toVersion,
        ?string $actor,
        array $item,
    ): void {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];

        try {
            $version = $this->versions->findVersionByUuid((string) $item['version_uuid']);
            if ($version === null) {
                throw new \RuntimeException('pinned version missing');
            }
            $migrated = $opSet->apply((array) $version['fields']);

            $this->db->transaction(function () use ($entry, $locale, $migrated, $toVersion, $actor, $schema): void {
                $number = $this->versions->reserveNextVersionNumber($entry, $locale);
                $newUuid = $this->versions->appendVersion($entry, $locale, $number, $migrated, $toVersion, $actor);
                $this->versions->pin($entry, $locale, $newUuid, $actor);
                $this->references->rebuildForEntry($entry, $schema, $migrated);
            });

            $this->migrations->incrementDone($migrationUuid);
        } catch (\Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'published', $e->getMessage());
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function draftItems(string $typeUuid, int $toVersion): array
    {
        return $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->select(['d.entry_uuid', 'd.locale', 'd.fields', 'd.schema_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '=', 'active')
            ->where('d.schema_version', '<', $toVersion)
            ->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publishedItems(string $typeUuid, int $toVersion): array
    {
        return $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['p.entry_uuid', 'p.locale', 'p.version_uuid', 'v.schema_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '=', 'active')
            ->where('v.schema_version', '<', $toVersion)
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeFields(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($fields) ? $fields : [];
    }

    private function invalidateCache(string $typeUuid): void
    {
        $type = $this->types->findByUuid($typeUuid);
        if ($type === null || !$this->container->has(CacheStore::class)) {
            return;
        }

        /** @var CacheStore $cache */
        $cache = $this->container->get(CacheStore::class);
        $cache->invalidateTags(['lemma:type:' . (string) $type['slug']]);
    }
}
