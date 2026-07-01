<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Glueful\Lemma\Search\Engine\MeilisearchBackend;
use Glueful\Lemma\Search\Engine\MeilisearchIndex;
use Glueful\Lemma\Search\Query\SearchRequest;
use PHPUnit\Framework\TestCase;

final class MeilisearchBackendTest extends TestCase
{
    private function fakeIndex(): MeilisearchIndex
    {
        return new class implements MeilisearchIndex {
            /** @var array<string,mixed> */
            public array $settings = [];
            /** @var list<array<string,mixed>> */
            public array $added = [];
            /** @var list<string> */
            public array $deletedIds = [];
            /** @var list<string> */
            public array $deletedFilters = [];
            public ?string $lastQuery = null;
            /** @var array<string,mixed> */
            public array $lastParams = [];
            /** @var array<string,mixed> */
            public array $searchResult = ['hits' => [], 'estimatedTotalHits' => 0];
            public bool $up = true;

            public function ensureIndex(array $settings): void
            {
                $this->settings = $settings;
            }
            public function addDocuments(array $documents): void
            {
                foreach ($documents as $d) {
                    $this->added[] = $d;
                }
            }
            public function deleteDocument(string $id): void
            {
                $this->deletedIds[] = $id;
            }
            public function deleteByFilter(string $filter): void
            {
                $this->deletedFilters[] = $filter;
            }
            public function rawSearch(string $query, array $params): array
            {
                $this->lastQuery = $query;
                $this->lastParams = $params;
                return $this->searchResult;
            }
            public function stats(): array
            {
                return ['numberOfDocuments' => count($this->added)];
            }
            public function reachable(): bool
            {
                return $this->up;
            }
        };
    }

    public function testEnsureIndexAppliesSearchableAndFilterableSettings(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->ensureIndex();

        self::assertSame(['title', 'body'], $index->settings['searchableAttributes']);
        self::assertContains('content_type_uuid', $index->settings['filterableAttributes']);
        self::assertContains('public_delivery', $index->settings['filterableAttributes']);
        self::assertContains('locale', $index->settings['filterableAttributes']);
        self::assertContains('content_type_slug', $index->settings['filterableAttributes']);
    }

    public function testUpsertForwardsDocuments(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->upsert([['id' => 'e-1:en', 'title' => 'x']]);
        self::assertSame('e-1:en', $index->added[0]['id']);
    }

    public function testDeleteEntryWithLocaleDeletesSingleDocumentId(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->deleteEntry('e-1', 'en');
        self::assertSame(['e-1:en'], $index->deletedIds);
        self::assertSame([], $index->deletedFilters);
    }

    public function testDeleteEntryWithNullLocaleDeletesAllEntryDocumentsByFilter(): void
    {
        $index = $this->fakeIndex();
        (new MeilisearchBackend($index, 40))->deleteEntry('e-1', null);
        self::assertSame([], $index->deletedIds);
        self::assertSame(['entry_uuid = "e-1"'], $index->deletedFilters);
    }

    public function testSearchBuildsVisibilityFilterAndMapsHits(): void
    {
        $index = $this->fakeIndex();
        $index->searchResult = [
            'estimatedTotalHits' => 1,
            'hits' => [[
                'entry_uuid' => 'e-9', 'content_type_slug' => 'blog', 'locale' => 'en',
                'href' => '/en/blog/x', 'title' => 'Clean Title',
                '_rankingScore' => 0.87,
                '_formatted' => ['body' => "the \x02climate\x03 crisis <script>"],
            ]],
        ];
        $backend = new MeilisearchBackend($index, 40);

        $req = new SearchRequest('climate', 'en', null, false, ['ct-a', 'ct-b'], 20, 0);
        $results = $backend->search($req);

        // Filter: locale AND (public OR scoped-in).
        $filter = $index->lastParams['filter'];
        self::assertStringContainsString('locale = "en"', $filter);
        self::assertStringContainsString('public_delivery = true', $filter);
        self::assertStringContainsString('content_type_uuid IN ["ct-a", "ct-b"]', $filter);

        self::assertSame(1, $results->total);
        $hit = $results->hits[0];
        self::assertSame('e-9', $hit->entryUuid);
        self::assertSame('Clean Title', $hit->title); // plain text, no highlight tags
        self::assertSame(0.87, $hit->score);
        // Snippet: highlight sentinels → <mark>, surrounding markup escaped.
        self::assertStringContainsString('<mark>climate</mark>', $hit->snippet);
        self::assertStringContainsString('&lt;script&gt;', $hit->snippet);
        self::assertStringNotContainsString('<script>', $hit->snippet);
    }

    public function testSearchAllAccessOmitsTypeUuidClauseAndTypeSlugWhenProvided(): void
    {
        $index = $this->fakeIndex();
        $backend = new MeilisearchBackend($index, 40);
        $backend->search(new SearchRequest('x', 'en', 'blog', true, [], 20, 0));

        $filter = $index->lastParams['filter'];
        self::assertStringContainsString('content_type_slug = "blog"', $filter);
        // all-access ⇒ no visibility narrowing to public/scoped.
        self::assertStringNotContainsString('public_delivery = true', $filter);
        self::assertStringNotContainsString('content_type_uuid IN', $filter);
    }

    public function testHealthReflectsIndexReachability(): void
    {
        $index = $this->fakeIndex();
        $backend = new MeilisearchBackend($index, 40);
        self::assertTrue($backend->health());
        $index->up = false;
        self::assertFalse($backend->health());
    }
}
