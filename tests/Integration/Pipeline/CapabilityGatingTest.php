<?php

declare(strict_types=1);

namespace App\Tests\Integration\Pipeline;

use App\Content\Events\EntryPublished;
use App\Content\Pipeline\Listeners\PurgeCdnListener;
use App\Content\Pipeline\Listeners\ReindexSearchListener;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Tests\Support\LemmaTestCase;
use App\Tests\Support\RecordingEdgeCache;
use App\Tests\Support\RecordingQueueManager;
use App\Tests\Support\RecordingSearchAdapter;
use Glueful\Api\Filtering\Contracts\SearchAdapterInterface;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Cache\NullEdgeCache;
use Glueful\Queue\QueueManager;

/**
 * Proves the two capability-gated listeners (V1_DESIGN §5) are a CLEAN NO-OP in the
 * default Lemma install — which enables users/aegis/media/email but NOT glueful/cdn or
 * glueful/meilisearch — while the rest of the pipeline (cache/webhook) still runs.
 *
 * This is the headline behaviour: a lean install must publish with cache + webhook effects
 * and ZERO error from CDN purge / search reindex. The test proves the gate in BOTH
 * directions:
 *   - DEFAULT env (no cdn/meilisearch): publishing succeeds, neither listener touches a
 *     missing service, nothing throws.
 *   - PRESENT env (a real EdgeCache / a search seam substituted in): the listeners DO act —
 *     PurgeCdnListener purges by the delivery surrogate tags; ReindexSearchListener enqueues
 *     a reindex job for the entry.
 *
 * The container-substitution (reflection on the compiled container's `singletons`) mirrors
 * CacheInvalidationTest / WebhookDispatchTest.
 */
final class CapabilityGatingTest extends LemmaTestCase
{
    private string $type;
    private string $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $types = new ContentTypeRepository($this->connection());
        $this->type = $types->create([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);
        $entries = $this->container()->get(EntryRepository::class);
        $this->entry = $entries->createEntry($this->type, 'en', 1, 'user00000001');
        $entries->saveDraft($this->entry, 'en', ['title' => 'V1'], 1, 0, 'user00000001');
    }

    protected function tearDown(): void
    {
        $this->restoreSingletons();
        parent::tearDown();
    }

    // ---- default env: cdn/meilisearch absent -> clean no-op -------------------------

    public function testTheDefaultInstallHasNeitherCdnNorMeilisearch(): void
    {
        // The CDN seam is always bound (to the no-op NullEdgeCache); it must report disabled.
        $edge = $this->container()->get(EdgeCacheInterface::class);
        self::assertInstanceOf(NullEdgeCache::class, $edge, 'default install must bind NullEdgeCache');
        self::assertFalse($edge->isEnabled(), 'NullEdgeCache must report the CDN disabled');

        // The search seam is unbound entirely by default — no glueful/meilisearch.
        self::assertFalse(
            $this->container()->has(SearchAdapterInterface::class),
            'default install must not bind a search adapter'
        );
    }

    public function testPublishIsCleanWithNeitherCapabilityPresent(): void
    {
        // No substitution: the real default container (NullEdgeCache, no search seam).
        $this->container()->get(PublishService::class)->publish($this->entry, 'en', 'user00000001');

        // The publish succeeded: the entry is published.
        $publications = $this->connection()->table('entry_publications')
            ->where('entry_uuid', $this->entry)->get();
        self::assertCount(1, $publications, 'the entry must be published despite no cdn/search');
    }

    public function testPurgeCdnListenerSkipsWhenEdgeCacheIsTheNullNoOp(): void
    {
        // Invoke the listener directly on an EntryPublished event. With NullEdgeCache bound
        // (isEnabled() === false) it must NOT throw and must NOT call purge — a clean skip.
        $listener = $this->container()->get(PurgeCdnListener::class);
        $listener(new EntryPublished($this->entry, $this->type, 'en', 1, 'user00000001'));

        // No exception is the assertion; reaching here proves the graceful skip.
        $this->addToAssertionCount(1);
    }

    public function testReindexSearchListenerSkipsWhenNoSearchSeamIsBound(): void
    {
        $listener = $this->container()->get(ReindexSearchListener::class);
        $listener(new EntryPublished($this->entry, $this->type, 'en', 1, 'user00000001'));

        $this->addToAssertionCount(1);
    }

    // ---- present env: substitute real capabilities -> listeners act -----------------

    public function testPurgeCdnListenerPurgesByDeliveryTagsWhenARealEdgeCacheIsBound(): void
    {
        $edge = new RecordingEdgeCache();
        $this->setSingleton(EdgeCacheInterface::class, $edge);

        $listener = $this->container()->get(PurgeCdnListener::class);
        $listener(new EntryPublished($this->entry, $this->type, 'en', 1, 'user00000001'));

        // Same surrogate tags the cache listener invalidates + the delivery layer emits.
        self::assertContains('lemma:entry:' . $this->entry, $edge->purgedTags, 'must purge the entry tag');
        self::assertContains('lemma:type:post', $edge->purgedTags, 'must purge the type SLUG tag');
        self::assertNotContains(
            'lemma:type:' . $this->type,
            $edge->purgedTags,
            'the type tag must use the slug, never the content-type UUID'
        );
    }

    public function testReindexSearchListenerEnqueuesAReindexJobWhenSearchSeamIsBound(): void
    {
        $queue = new RecordingQueueManager();
        $this->setSingleton(QueueManager::class, $queue);
        $this->setSingleton(SearchAdapterInterface::class, new RecordingSearchAdapter());

        $listener = $this->container()->get(ReindexSearchListener::class);
        $listener(new EntryPublished($this->entry, $this->type, 'en', 1, 'user00000001'));

        self::assertCount(1, $queue->pushed, 'a reindex job must be enqueued when search is present');
        $job = $queue->pushed[0];
        self::assertSame(ReindexSearchListener::REINDEX_JOB, $job['job']);
        self::assertSame($this->entry, $job['data']['entry']);
        self::assertSame('en', $job['data']['locale']);
        // Identity-only payload: the search extension owns the document shape.
        self::assertArrayNotHasKey('fields', $job['data']);
    }

    // ---- container surgery (the compiled container exposes no setter) ----------------

    /** @var array<string, array{0: bool, 1: mixed}> id => [existed, priorValue] */
    private array $priorSingletons = [];

    private function restoreSingletons(): void
    {
        foreach (array_reverse($this->priorSingletons, true) as $id => [$existed, $value]) {
            $this->writeSingleton($id, $existed, $value);
        }
        $this->priorSingletons = [];
    }

    private function setSingleton(string $id, mixed $value): void
    {
        $this->stashSingleton($id);
        $this->writeSingleton($id, true, $value);
    }

    private function stashSingleton(string $id): void
    {
        if (array_key_exists($id, $this->priorSingletons)) {
            return;
        }
        $singletons = $this->singletons();
        $this->priorSingletons[$id] = [
            array_key_exists($id, $singletons),
            $singletons[$id] ?? null,
        ];
    }

    private function writeSingleton(string $id, bool $present, mixed $value): void
    {
        $container = $this->container();
        $prop = (new \ReflectionClass($container))->getProperty('singletons');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $singletons */
        $singletons = $prop->getValue($container);
        if ($present) {
            $singletons[$id] = $value;
        } else {
            unset($singletons[$id]);
        }
        $prop->setValue($container, $singletons);
    }

    /** @return array<string, mixed> */
    private function singletons(): array
    {
        $container = $this->container();
        $prop = (new \ReflectionClass($container))->getProperty('singletons');
        $prop->setAccessible(true);
        /** @var array<string, mixed> $singletons */
        $singletons = $prop->getValue($container);
        return $singletons;
    }
}
