<?php

declare(strict_types=1);

namespace App\Content\Pipeline\Listeners;

use App\Content\Events\BaseEntryEvent;
use Glueful\Api\Filtering\Contracts\SearchAdapterInterface;
use Glueful\Queue\QueueManager;
use Psr\Container\ContainerInterface;

/**
 * Enqueues a search-index reindex for an entry when content changes (V1_DESIGN §5).
 *
 * CAPABILITY-GATED. The default Lemma install enables users/aegis/media/email but NOT
 * glueful/meilisearch, so by default there is NO search engine bound and this listener is a
 * CLEAN skip — no error, no exception.
 *
 * The gate is the presence of the framework's search seam in the container:
 * {@see SearchAdapterInterface}. Core does NOT bind it (CoreProvider binds only the filter
 * parser/middleware), so by default `container->has(SearchAdapterInterface::class)` is false;
 * installing glueful/meilisearch binds a real adapter and flips the gate on. The interface
 * itself ships in the framework core, so referencing it here is safe even with no search
 * extension installed (we never import a class that might not exist).
 *
 * Why a queued JOB, not a direct index() call: reindexing is heavy and the search extension
 * owns the document shape. Lemma's side is deliberately minimal — when the capability is
 * present it pushes a reindex job (job class {@see self::REINDEX_JOB}, owned by the search
 * extension) with an IDENTITY-ONLY payload (entry uuid + locale, NEVER field values). The
 * worker re-reads the published version through the delivery API and builds the document.
 * This keeps Lemma free of any search infrastructure.
 *
 * Registered for entry lifecycle events only (publish/unpublish/update/delete) — a published
 * document's searchable state changes on each. Model/asset events do not move a single
 * entry's index document, so they are not wired to this listener.
 *
 * Both the seam and the QueueManager are resolved from the container per-invocation (not the
 * constructor): this is a long-lived singleton registered at boot, so lazy resolution always
 * uses the current binding and lets a test substitute spies after boot.
 *
 * Registered via EventService::addListener(..., '@' . self::class) — the '@serviceId' form
 * resolves this service lazily and invokes it as a callable, so the entry point is
 * __invoke(object $event). Idempotent + re-drivable: re-pushing a reindex job re-derives the
 * same document, so `lemma:resync` can safely re-run it.
 */
final class ReindexSearchListener
{
    /**
     * The reindex job class the search extension (glueful/meilisearch) registers with the
     * queue. Referenced by STRING — Lemma neither imports nor owns it; this is only the
     * job-class name pushed onto the queue, resolved by the worker when the extension is
     * installed. Kept as a constant so the contract is explicit and testable.
     */
    public const REINDEX_JOB = 'Glueful\\Meilisearch\\Jobs\\ReindexEntryJob';

    public function __construct(
        private readonly ContainerInterface $container,
    ) {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof BaseEntryEvent) {
            return;
        }

        // Gate: only act when a real search engine is installed (glueful/meilisearch).
        if (!$this->container->has(SearchAdapterInterface::class)) {
            return;
        }

        $this->queue()->push(self::REINDEX_JOB, [
            'entry' => $event->entry,
            'locale' => $event->locale,
        ]);
    }

    private function queue(): QueueManager
    {
        /** @var QueueManager $queue */
        $queue = $this->container->get(QueueManager::class);
        return $queue;
    }
}
