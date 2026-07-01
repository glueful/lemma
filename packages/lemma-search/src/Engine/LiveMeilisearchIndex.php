<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Engine;

use Glueful\Extensions\Meilisearch\Indexing\IndexManager;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * The ONLY class in this pack that imports Glueful\Extensions\Meilisearch\*. Wraps the
 * extension's IndexManager (lifecycle/settings/stats) and the raw index endpoint
 * (documents + search) for the pack-owned MeilisearchIndex seam.
 */
final class LiveMeilisearchIndex implements MeilisearchIndex
{
    public function __construct(
        private readonly IndexManager $manager,
        private readonly string $indexName,
    ) {
    }

    public static function fromContainer(ContainerInterface $container, string $indexName): self
    {
        return new self($container->get(IndexManager::class), $indexName);
    }

    public function ensureIndex(array $settings): void
    {
        $this->manager->getOrCreateIndex($this->indexName);
        $this->manager->updateSettings($this->indexName, $settings);
    }

    public function addDocuments(array $documents): void
    {
        // 'id' is the Meilisearch primary key by convention (see IndexManager::createIndex).
        $this->manager->getOrCreateIndex($this->indexName)->addDocuments($documents, 'id');
    }

    public function deleteDocument(string $id): void
    {
        $this->manager->getOrCreateIndex($this->indexName)->deleteDocument($id);
    }

    public function deleteByFilter(string $filter): void
    {
        // meilisearch-php: filtered delete via deleteDocuments(['filter' => …]).
        $this->manager->getOrCreateIndex($this->indexName)->deleteDocuments(['filter' => $filter]);
    }

    public function rawSearch(string $query, array $params): array
    {
        // rawSearch returns the direct Meilisearch response array (hits with _formatted /
        // _rankingScore, estimatedTotalHits) — no SearchResult wrapper.
        return $this->manager->getOrCreateIndex($this->indexName)->rawSearch($query, $params);
    }

    public function stats(): array
    {
        return $this->manager->getStats($this->indexName);
    }

    public function reachable(): bool
    {
        try {
            $this->manager->getStats($this->indexName);
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
