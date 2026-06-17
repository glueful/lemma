<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Backfill\BackfillRunner;
use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\MigrationService;
use App\Tests\Support\LemmaTestCase;

final class BackfillRunnerTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->connection()->getSchemaBuilder()->hasTable('queue_jobs')) {
            $this->connection()->table('queue_jobs')->where('id', '>', 0)->delete();
        }
    }

    public function testPublishedEntryGetsNewMigratedVersionAndRepinOldPreserved(): void
    {
        [$type, $entry] = $this->seedRenameMigration(publishedFields: ['title' => 'Hi']);
        $oldVersion = $this->connection()->table('entry_versions')
            ->where('entry_uuid', '=', $entry)
            ->first();

        $this->runner()->run($this->migrationUuid($type));

        $versions = $this->connection()->table('entry_versions')
            ->where('entry_uuid', '=', $entry)
            ->orderBy('version', 'ASC')
            ->get();
        self::assertCount(2, $versions);
        self::assertSame($oldVersion['fields'], $versions[0]['fields']);
        self::assertSame(2, (int) $versions[1]['version']);
        self::assertSame(2, (int) $versions[1]['schema_version']);
        self::assertSame(['heading' => 'Hi'], json_decode((string) $versions[1]['fields'], true));

        $publication = $this->versions()->findPublication($entry, 'en');
        self::assertSame((string) $versions[1]['uuid'], (string) $publication['version_uuid']);
    }

    public function testDraftTransformedInPlaceAndReTagged(): void
    {
        [$type, $entry] = $this->seedRenameMigration(draftFields: ['title' => 'Hi']);

        $this->runner()->run($this->migrationUuid($type));

        $draft = $this->entries()->findDraft($entry, 'en');
        self::assertSame(['heading' => 'Hi'], $draft['fields']);
        self::assertSame(2, $draft['schema_version']);
    }

    public function testReRunIsIdempotent(): void
    {
        [$type, $entry] = $this->seedRenameMigration(publishedFields: ['title' => 'Hi']);
        $uuid = $this->migrationUuid($type);

        $this->runner()->run($uuid);
        $afterFirst = (int) $this->connection()->table('entry_versions')
            ->where('entry_uuid', '=', $entry)
            ->count();
        $this->runner()->run($uuid);

        self::assertSame(
            $afterFirst,
            (int) $this->connection()->table('entry_versions')->where('entry_uuid', '=', $entry)->count()
        );
    }

    public function testWorkItemPartialFailureDraftDonePublishedFailed(): void
    {
        [$type, $entry] = $this->seedRenameMigration(
            draftFields: ['title' => 'Hi'],
            publishedFields: ['title' => 'Hi', 'heading' => 'already'],
        );

        $this->runner()->run($this->migrationUuid($type));

        $row = $this->migrations()->find($this->migrationUuid($type));
        self::assertSame('failed', $row['status']);
        self::assertSame(1, $row['work_items_done']);
        self::assertSame(1, $row['work_items_failed']);
        self::assertSame('published', $row['failure_report'][0]['kind']);
        self::assertSame(['heading' => 'Hi'], $this->entries()->findDraft($entry, 'en')['fields']);
    }

    public function testResumeMaterializesRemainderAndFlipsFailedToCompleted(): void
    {
        [$type, $entry] = $this->seedRenameMigration(
            draftFields: ['title' => 'Hi'],
            publishedFields: ['title' => 'Hi', 'heading' => 'already'],
        );
        $uuid = $this->migrationUuid($type);

        $this->runner()->run($uuid);
        $publication = $this->versions()->findPublication($entry, 'en');
        $this->connection()->table('entry_versions')
            ->where('uuid', '=', (string) $publication['version_uuid'])
            ->update(['fields' => json_encode(['title' => 'Hi'], JSON_THROW_ON_ERROR)]);

        $this->runner()->run($uuid);

        self::assertSame('completed', $this->migrations()->find($uuid)['status']);
    }

    public function testEnqueuesEnsureFilterIndexesJobForTheType(): void
    {
        [$type] = $this->seedRenameMigration(publishedFields: ['title' => 'Hi']);

        $this->runner()->run($this->migrationUuid($type));

        self::assertTrue($this->queueContains(EnsureFilterIndexesJob::class, ['content_type_uuid' => $type]));
    }

    public function testDeletingAFilterableFieldDropsItsRegistryViaTheEnqueuedJob(): void
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'score', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number']],
        ]);
        $this->connection()->table('lemma_filter_indexes')->insert([
            'uuid' => 'idxaaaaaaaaa',
            'content_type_uuid' => $type,
            'field' => 'score',
            'filter_type' => 'number',
            'index_name' => 'idx_lemma_filter_score',
            'status' => 'ready',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->service()->migrate($type, [['op' => 'delete', 'name' => 'score']], null);

        $this->runner()->run($this->migrationUuid($type));

        self::assertTrue($this->queueContains(EnsureFilterIndexesJob::class, ['content_type_uuid' => $type]));
        self::assertNotNull($this->connection()->table('lemma_filter_indexes')->where('field', '=', 'score')->first());

        (new EnsureFilterIndexesJob([], $this->appContext()))
            ->reconcile($this->connection(), $this->types(), $type);

        self::assertNull($this->connection()->table('lemma_filter_indexes')->where('field', '=', 'score')->first());
    }

    /**
     * @param array<string,mixed>|null $draftFields
     * @param array<string,mixed>|null $publishedFields
     * @return array{0:string,1:string}
     */
    private function seedRenameMigration(?array $draftFields = null, ?array $publishedFields = null): array
    {
        $type = $this->types()->create([
            'slug' => 'article',
            'name' => 'Article',
            'schema' => [['name' => 'title', 'type' => 'string']],
        ]);
        $entry = $this->entries()->createEntry($type, 'en', 1, 'user00000001');

        if ($draftFields === null) {
            $this->connection()->table('entry_drafts')
                ->where('entry_uuid', '=', $entry)
                ->where('locale', '=', 'en')
                ->delete();
        } else {
            $this->entries()->saveDraft($entry, 'en', $draftFields, 1, 0, 'user00000001');
        }

        if ($publishedFields !== null) {
            $version = $this->versions()->appendVersion($entry, 'en', 1, $publishedFields, 1, 'user00000001');
            $this->versions()->pin($entry, 'en', $version, 'user00000001');
        }

        $this->service()->migrate($type, [['op' => 'rename', 'from' => 'title', 'to' => 'heading']], null);

        return [$type, $entry];
    }

    private function migrationUuid(string $typeUuid): string
    {
        $active = $this->migrations()->activeForType($typeUuid);
        if ($active !== null) {
            return (string) $active['uuid'];
        }

        return (string) $this->migrations()->forType($typeUuid)[0]['uuid'];
    }

    /** @param array<string,mixed> $expectedData */
    private function queueContains(string $jobClass, array $expectedData): bool
    {
        foreach ($this->connection()->table('queue_jobs')->get() as $row) {
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload) || ($payload['job'] ?? null) !== $jobClass) {
                continue;
            }
            foreach ($expectedData as $key => $value) {
                if (($payload['data'][$key] ?? null) !== $value) {
                    continue 2;
                }
            }
            return true;
        }

        return false;
    }

    private function runner(): BackfillRunner
    {
        return $this->container()->get(BackfillRunner::class);
    }

    private function service(): MigrationService
    {
        return $this->container()->get(MigrationService::class);
    }

    private function migrations(): MigrationRepository
    {
        return new MigrationRepository($this->connection());
    }

    private function types(): ContentTypeRepository
    {
        return new ContentTypeRepository($this->connection());
    }

    private function entries(): EntryRepository
    {
        return new EntryRepository($this->connection(), $this->appContext(), $this->types());
    }

    private function versions(): VersionRepository
    {
        return new VersionRepository($this->connection());
    }
}
