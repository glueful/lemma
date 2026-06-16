<?php

declare(strict_types=1);

namespace App\Content\Indexing;

use App\Content\Repositories\ContentTypeRepository;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Queue\Job;
use Psr\Log\LoggerInterface;

/**
 * Reconciles a content type's filterable-field expression indexes against the registry.
 *
 * For each *desired* index (from the type's current schema) missing from the registry it runs
 * `CREATE INDEX CONCURRENTLY IF NOT EXISTS ... ON entry_versions (<expr>)`; for each registry
 * row whose field is no longer filterable it runs `DROP INDEX CONCURRENTLY IF EXISTS ...`.
 * Idempotent.
 *
 * `CREATE INDEX CONCURRENTLY` cannot run inside a transaction, so the DDL is executed via the
 * raw PDO (`Connection::getPDO()->exec()`) outside any transaction — never through the query
 * builder's transactional path. On a host that forbids CONCURRENTLY (managed Postgres) the job
 * logs and marks the registry row `failed` instead of crashing (Task 4 enforces "unindexed ⇒
 * not filterable").
 *
 * Dependencies are resolved from the container at run time because the queue reconstructs a job
 * from its serialized payload + context (constructor is `(array $data, ?ApplicationContext)`).
 */
final class EnsureFilterIndexesJob extends Job
{
    private const TABLE = 'entry_versions';

    public function handle(): void
    {
        $context = $this->context;
        if (!$context instanceof ApplicationContext) {
            throw new \RuntimeException('EnsureFilterIndexesJob requires an ApplicationContext to run.');
        }

        $data = $this->getData();
        $typeUuid = isset($data['content_type_uuid']) && is_string($data['content_type_uuid'])
            ? $data['content_type_uuid']
            : '';
        if ($typeUuid === '') {
            throw new \InvalidArgumentException('EnsureFilterIndexesJob: missing content_type_uuid.');
        }

        $container = $context->getContainer();
        /** @var Connection $db */
        $db = $container->get(Connection::class);
        /** @var ContentTypeRepository $types */
        $types = $container->get(ContentTypeRepository::class);
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;

        $this->reconcile($db, $types, $typeUuid, $logger);
    }

    /**
     * @param LoggerInterface|null $logger
     */
    public function reconcile(
        Connection $db,
        ContentTypeRepository $types,
        string $typeUuid,
        ?LoggerInterface $logger = null
    ): void {
        $schema = $types->schemaFor($typeUuid);
        $desired = (new FilterIndexPlanner())->desiredIndexes($schema, $typeUuid);
        $desiredByName = [];
        foreach ($desired as $d) {
            $desiredByName[$d['index_name']] = $d;
        }

        $existing = $db->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->get();
        $existingByName = [];
        foreach ($existing as $row) {
            $existingByName[(string) $row['index_name']] = $row;
        }

        // Create / ensure desired indexes.
        foreach ($desired as $d) {
            $this->assertSafeName($d['index_name']);
            $current = $existingByName[$d['index_name']] ?? null;
            if ($current === null || ($current['status'] ?? '') !== 'ready') {
                $this->createIndex($db, $typeUuid, $d, $logger);
            }
        }

        // Drop indexes that are no longer desired.
        foreach ($existingByName as $name => $row) {
            if (isset($desiredByName[$name])) {
                continue;
            }
            $this->assertSafeName($name);
            $this->dropIndex($db, (string) $name, $logger);
            $db->table('lemma_filter_indexes')
                ->where('content_type_uuid', '=', $typeUuid)
                ->where('index_name', '=', $name)
                ->delete();
        }
    }

    /**
     * @param array{field:string,filter_type:string,index_name:string,expression:string} $d
     */
    private function createIndex(
        Connection $db,
        string $typeUuid,
        array $d,
        ?LoggerInterface $logger
    ): void {
        $name = $d['index_name'];
        $this->upsertRegistry($db, $typeUuid, $d, 'pending');

        $sql = sprintf(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS %s ON %s (%s)',
            $name,
            self::TABLE,
            $d['expression']
        );
        try {
            // CREATE INDEX CONCURRENTLY cannot run inside a transaction — execute on the
            // raw PDO outside any transaction.
            $db->getPDO()->exec($sql);
            $this->markStatus($db, $typeUuid, $name, 'ready');
        } catch (\Throwable $e) {
            $this->markStatus($db, $typeUuid, $name, 'failed');
            $logger?->warning('EnsureFilterIndexesJob: failed to create expression index', [
                'index' => $name,
                'content_type_uuid' => $typeUuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dropIndex(Connection $db, string $name, ?LoggerInterface $logger): void
    {
        $sql = sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $name);
        try {
            $db->getPDO()->exec($sql);
        } catch (\Throwable $e) {
            $logger?->warning('EnsureFilterIndexesJob: failed to drop expression index', [
                'index' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{field:string,filter_type:string,index_name:string,expression:string} $d
     */
    private function upsertRegistry(Connection $db, string $typeUuid, array $d, string $status): void
    {
        $exists = $db->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('field', '=', $d['field'])
            ->first();
        if ($exists === null) {
            $db->table('lemma_filter_indexes')->insert([
                'uuid' => Utils::generateNanoID(12),
                'content_type_uuid' => $typeUuid,
                'field' => $d['field'],
                'filter_type' => $d['filter_type'],
                'index_name' => $d['index_name'],
                'status' => $status,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            return;
        }
        $db->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('field', '=', $d['field'])
            ->update([
                'filter_type' => $d['filter_type'],
                'index_name' => $d['index_name'],
                'status' => $status,
            ]);
    }

    private function markStatus(Connection $db, string $typeUuid, string $name, string $status): void
    {
        $db->table('lemma_filter_indexes')
            ->where('content_type_uuid', '=', $typeUuid)
            ->where('index_name', '=', $name)
            ->update(['status' => $status]);
    }

    private function assertSafeName(string $name): void
    {
        if (preg_match('/\A[a-z0-9_]+\z/', $name) !== 1) {
            throw new \InvalidArgumentException("unsafe index name: '{$name}'");
        }
    }
}
