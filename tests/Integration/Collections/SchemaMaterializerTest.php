<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Lemma\Collections\Schema\CollectionField;
use Glueful\Lemma\Collections\Schema\DdlPlanner;
use Glueful\Lemma\Collections\Schema\SchemaMaterializer;

final class SchemaMaterializerTest extends LemmaTestCase
{
    private const TEST_TABLE = 'collection_clx1';

    protected function setUp(): void
    {
        parent::setUp();

        // Drop any leftover test table from a prior run (idempotent).
        $schema = $this->schema();
        if ($schema->hasTable(self::TEST_TABLE)) {
            $schema->dropTableIfExists(self::TEST_TABLE);
        }

        // collection_schema_changes is not in LemmaTestCase::TABLES; purge it here.
        $this->connection()->table('collection_schema_changes')->where('id', '>', 0)->delete();
    }

    protected function tearDown(): void
    {
        // Drop the test table so it doesn't bleed into other runs.
        $schema = $this->schema();
        if ($schema->hasTable(self::TEST_TABLE)) {
            $schema->dropTableIfExists(self::TEST_TABLE);
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    // ----------------------------------------------------------------- tests

    public function testCreateTableMaterializesRealTableWithSystemColumns(): void
    {
        [$mat, $schema] = [$this->container()->get(SchemaMaterializer::class), $this->schema()];
        $def = new CollectionDefinition('clx_1', 'products', 'Products', 'collection_clx1', 'table', [
            CollectionField::fromArray([
                'name'     => 'title',
                'type'     => 'collections.text',
                'settings' => ['length' => 120],
            ]),
        ], 1, 'active');
        $mat->apply($def, (new DdlPlanner())->planCreate($def), 'admin', 'u1');

        self::assertTrue($schema->hasTable('collection_clx1'));
        $expectedCols = [
            'id', 'uuid', 'created_at', 'updated_at',
            'created_by_type', 'created_by_id',
            'updated_by_type', 'updated_by_id',
            'title',
        ];
        foreach ($expectedCols as $col) {
            self::assertTrue($schema->hasColumn('collection_clx1', $col), $col);
        }
        self::assertSame(
            1,
            $this->connection()->table('collection_schema_changes')->where('collection_uuid', 'clx_1')->count(),
        );
    }
}
