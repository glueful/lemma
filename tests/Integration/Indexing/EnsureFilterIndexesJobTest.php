<?php

declare(strict_types=1);

namespace App\Tests\Integration\Indexing;

use App\Content\Indexing\EnsureFilterIndexesJob;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\LemmaTestCase;

final class EnsureFilterIndexesJobTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The registry table lives outside LemmaTestCase's truncate set; clear it per test.
        $this->connection()->table('lemma_filter_indexes')->where('id', '>', 0)->delete();
    }

    /** Drop any expression indexes a run may have created so reruns stay clean. */
    protected function tearDown(): void
    {
        $rows = $this->connection()->table('lemma_filter_indexes')->select(['index_name'])->get();
        foreach ($rows as $row) {
            $name = (string) $row['index_name'];
            if (preg_match('/\A[a-z0-9_]+\z/', $name) === 1) {
                $this->connection()->getPDO()->exec("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }
        }
        $this->connection()->table('lemma_filter_indexes')->where('id', '>', 0)->delete();
        parent::tearDown();
    }

    private function createType(array $schema): string
    {
        return (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'product',
            'name' => 'Product',
            'schema' => $schema,
        ]);
    }

    private function updateSchema(string $uuid, array $schema): void
    {
        (new ContentTypeRepository($this->connection()))->updateSchema($uuid, $schema);
    }

    private function runJob(string $typeUuid): void
    {
        $job = new EnsureFilterIndexesJob(['content_type_uuid' => $typeUuid], $this->appContext());
        $job->handle();
    }

    /** @return array{name:string,def:string}|null */
    private function findExpressionIndex(string $expressionFragment): ?array
    {
        $rows = $this->connection()->table('pg_indexes')
            ->select(['indexname', 'indexdef'])
            ->where('tablename', '=', 'entry_versions')
            ->get();
        foreach ($rows as $row) {
            if (str_contains((string) $row['indexdef'], $expressionFragment)) {
                return ['name' => (string) $row['indexname'], 'def' => (string) $row['indexdef']];
            }
        }
        return null;
    }

    public function testCreatesExpressionIndexAndRecordsRegistry(): void
    {
        $type = $this->createType([
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);

        $this->runJob($type);

        $idx = $this->findExpressionIndex("(fields ->> 'price'::text))::numeric");
        self::assertNotNull($idx, 'expected a numeric expression index on (fields->>price) on entry_versions');

        $reg = $this->connection()->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->where('field', '=', 'price')
            ->first();
        self::assertNotNull($reg);
        self::assertSame('ready', $reg['status']);
        self::assertSame($idx['name'], $reg['index_name']);
    }

    public function testRerunIsIdempotent(): void
    {
        $type = $this->createType([
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);

        $this->runJob($type);
        $this->runJob($type); // must not error or duplicate

        $count = $this->connection()->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->count();
        self::assertSame(1, $count);
        self::assertNotNull($this->findExpressionIndex("(fields ->> 'price'::text))::numeric"));
    }

    public function testRemovingFilterableFieldDropsIndexAndRegistry(): void
    {
        $type = $this->createType([
            ['name' => 'price', 'type' => 'number', 'filterable' => true, 'filter_type' => 'number'],
        ]);
        $this->runJob($type);
        self::assertNotNull($this->findExpressionIndex("(fields ->> 'price'::text))::numeric"));

        // price is no longer filterable.
        $this->updateSchema($type, [
            ['name' => 'price', 'type' => 'number'],
        ]);
        $this->runJob($type);

        self::assertNull(
            $this->findExpressionIndex("(fields ->> 'price'::text))::numeric"),
            'index should be dropped once the field is no longer filterable'
        );
        $count = $this->connection()->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $type)
            ->count();
        self::assertSame(0, $count);
    }
}
