<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Api\Filtering\Contracts\SearchAdapterInterface;
use Glueful\Api\Filtering\SearchResult;

/**
 * A SearchAdapterInterface stand-in standing for the search engine the glueful/meilisearch
 * extension would bind. Its mere PRESENCE in the container is the capability signal the
 * ReindexSearchListener gates on — it does not call this adapter directly (the reindex is
 * deferred to a queued job the search extension owns). It exists only so the container
 * `has(SearchAdapterInterface::class)` check returns true in the present-env test.
 */
final class RecordingSearchAdapter implements SearchAdapterInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function search(string $query, array $options = []): SearchResult
    {
        return new SearchResult([], 0, 0.0);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function index(string $id, array $document): void
    {
    }

    /**
     * @param array<string, mixed> $document
     */
    public function update(string $id, array $document): void
    {
    }

    public function delete(string $id): void
    {
    }

    /**
     * @param array<string, array<string, mixed>> $documents
     */
    public function bulkIndex(array $documents): void
    {
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'recording';
    }
}
