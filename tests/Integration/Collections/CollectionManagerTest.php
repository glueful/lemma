<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Lemma\Collections\CollectionManager;
use Glueful\Lemma\Collections\Exceptions\CollectionValidationException;
use Glueful\Lemma\Collections\Exceptions\DestructiveConfirmationRequiredException;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;

final class CollectionManagerTest extends LemmaTestCase
{
    /**
     * Names used across tests — table names are derived deterministically as
     * 'collection_' . substr(hash('sha256', $name), 0, 12).
     *
     * @var list<string>
     */
    private const TEST_NAMES = ['articles', 'products', 'events'];

    // ----------------------------------------------------------------- setup / teardown

    protected function setUp(): void
    {
        parent::setUp();

        $schema = $this->schema();
        $schema->reset();

        // Purge metadata rows that LemmaTestCase::TABLES does not cover.
        $this->connection()->table('collection_schema_changes')->where('id', '>', 0)->delete();
        $this->connection()->table('collection_definitions')->where('id', '>', 0)->delete();

        // Drop any collection data tables from a prior run.
        foreach (self::TEST_NAMES as $name) {
            $table = $this->tableNameFor($name);
            if ($schema->hasTable($table)) {
                $schema->dropTableIfExists($table);
            }
        }
    }

    protected function tearDown(): void
    {
        $schema = $this->schema();
        $schema->reset();

        foreach (self::TEST_NAMES as $name) {
            $table = $this->tableNameFor($name);
            if ($schema->hasTable($table)) {
                $schema->dropTableIfExists($table);
            }
        }

        parent::tearDown();
    }

    // ----------------------------------------------------------------- helpers

    private function schema(): SchemaBuilderInterface
    {
        return $this->container()->get(SchemaBuilderInterface::class);
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function repo(): CollectionDefinitionRepository
    {
        return $this->container()->get(CollectionDefinitionRepository::class);
    }

    /**
     * Compute the deterministic table name for a given collection name,
     * matching the algorithm in CollectionManager.
     */
    private function tableNameFor(string $name): string
    {
        return 'collection_' . substr(hash('sha256', $name), 0, 12);
    }

    // ----------------------------------------------------------------- tests

    /**
     * create() materializes the physical table with all system columns and the
     * user-defined field column, and persists the definition row.
     */
    public function testCreateMaterializesTableWithSystemAndUserColumns(): void
    {
        $schema = $this->schema();
        $def    = $this->manager()->create([
            'name'   => 'articles',
            'label'  => 'Articles',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.text', 'settings' => []],
            ],
        ], 'admin', 'u1');

        self::assertSame('articles', $def->name);
        self::assertSame('table', $def->storageMode);
        self::assertSame(1, $def->schemaVersion);
        self::assertTrue($schema->hasTable($def->tableName));

        $expectedCols = [
            'id', 'uuid', 'created_at', 'updated_at',
            'created_by_type', 'created_by_id',
            'updated_by_type', 'updated_by_id',
            'title',
        ];
        foreach ($expectedCols as $col) {
            self::assertTrue($schema->hasColumn($def->tableName, $col), "Missing column: {$col}");
        }

        // Definition row is persisted.
        $stored = $this->repo()->findByName('articles');
        self::assertNotNull($stored, 'CollectionDefinition must be persisted');
        self::assertSame($def->uuid, $stored->uuid);
    }

    /**
     * create() with storage_mode = 'document' must throw CollectionValidationException
     * with an error keyed 'storage_mode'.
     */
    public function testCreateWithDocumentStorageModeIsRejected(): void
    {
        $caught = null;
        try {
            $this->manager()->create([
                'name'         => 'articles',
                'storage_mode' => 'document',
                'fields'       => [],
            ], 'admin', null);
        } catch (CollectionValidationException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'CollectionValidationException must be thrown for unsupported storage_mode');
        self::assertArrayHasKey('storage_mode', $caught->errors());
        self::assertSame(
            'Only table storage is supported in v1.',
            $caught->errors()['storage_mode'],
        );
    }

    /**
     * create() with no storage_mode key silently defaults to 'table' and succeeds.
     */
    public function testCreateWithMissingStorageModeDefaultsToTableAndSucceeds(): void
    {
        $schema = $this->schema();
        $def    = $this->manager()->create([
            'name'   => 'articles',
            'label'  => 'Articles',
            'fields' => [],
        ], 'admin', null);

        self::assertSame('table', $def->storageMode);
        self::assertTrue($schema->hasTable($def->tableName));
    }

    /**
     * create() with a field whose name collides with a system column must throw
     * CollectionValidationException.
     */
    public function testCreateWithReservedFieldNameIsRejected(): void
    {
        $this->expectException(CollectionValidationException::class);

        $this->manager()->create([
            'name'   => 'articles',
            'fields' => [
                ['name' => 'uuid', 'type' => 'collections.text', 'settings' => []],
            ],
        ], 'admin', null);
    }

    /**
     * addField() adds the column to the physical table and bumps schema_version.
     */
    public function testAddFieldAltersTableAndColumnAppears(): void
    {
        $schema  = $this->schema();
        $manager = $this->manager();

        $def = $manager->create([
            'name'   => 'articles',
            'label'  => 'Articles',
            'fields' => [],
        ], 'admin', null);

        self::assertFalse($schema->hasColumn($def->tableName, 'bio'), 'bio must not exist before addField');

        $updated = $manager->addField(
            'articles',
            ['name' => 'bio', 'type' => 'collections.longtext', 'settings' => []],
            'admin',
            null,
        );

        self::assertTrue($schema->hasColumn($def->tableName, 'bio'), 'bio must exist after addField');
        self::assertSame(2, $updated->schemaVersion, 'schema_version must be bumped to 2');

        // Persisted definition is also updated.
        $stored = $this->repo()->findByName('articles');
        self::assertNotNull($stored);
        self::assertSame(2, $stored->schemaVersion);
    }

    /**
     * dropField() without confirmation on a non-empty table must throw
     * DestructiveConfirmationRequiredException.
     */
    public function testDropFieldWithoutConfirmOnPopulatedTableThrows(): void
    {
        $manager = $this->manager();
        $def     = $manager->create([
            'name'   => 'articles',
            'fields' => [
                ['name' => 'title', 'type' => 'collections.text', 'settings' => []],
            ],
        ], 'admin', null);

        // Seed a row so the table is non-empty.
        $this->connection()->table($def->tableName)->insert([
            'uuid'            => 'test-uuid-001',
            'title'           => 'hello',
            'created_at'      => null,
            'updated_at'      => null,
            'created_by_type' => null,
            'created_by_id'   => null,
            'updated_by_type' => null,
            'updated_by_id'   => null,
        ]);

        $this->expectException(DestructiveConfirmationRequiredException::class);

        // No confirmation token → must throw.
        $manager->dropField('articles', 'title', [], 'admin', null);
    }

    /**
     * dropField() on an empty table succeeds without confirmation and the column is
     * physically removed (hasColumn returns false).
     */
    public function testDropFieldOnEmptyTableSucceedsWithoutConfirmAndColumnIsGone(): void
    {
        $schema  = $this->schema();
        $manager = $this->manager();

        $def = $manager->create([
            'name'   => 'articles',
            'fields' => [
                ['name' => 'notes', 'type' => 'collections.longtext', 'settings' => []],
            ],
        ], 'admin', null);

        self::assertTrue($schema->hasColumn($def->tableName, 'notes'), 'notes must exist after create');
        self::assertSame(
            0,
            $this->connection()->table($def->tableName)->count(),
            'table must be empty (prerequisite for light path)',
        );

        // Empty table — no confirmation required.
        $manager->dropField('articles', 'notes', [], 'admin', null);

        self::assertFalse(
            $schema->hasColumn($def->tableName, 'notes'),
            'notes must be physically removed after dropField on an empty table',
        );
    }

    /**
     * dropCollection() with the correct confirm token drops the physical table and
     * removes the definition row from collection_definitions.
     */
    public function testDropCollectionWithConfirmDropsTableAndDefinitionRow(): void
    {
        $schema  = $this->schema();
        $manager = $this->manager();

        $def       = $manager->create([
            'name'   => 'articles',
            'fields' => [],
        ], 'admin', null);
        $tableName = $def->tableName;

        self::assertTrue($schema->hasTable($tableName), 'table must exist before dropCollection');

        $manager->dropCollection('articles', ['confirm' => 'articles'], 'admin', null);

        self::assertFalse(
            $schema->hasTable($tableName),
            'table must be physically dropped after dropCollection',
        );
        self::assertNull(
            $this->repo()->findByName('articles'),
            'definition row must be deleted after dropCollection',
        );
    }
}
