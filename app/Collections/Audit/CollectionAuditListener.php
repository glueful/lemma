<?php

declare(strict_types=1);

namespace App\Collections\Audit;

use Glueful\Events\EventService;
use Glueful\Lemma\Collections\Events\CollectionRowCreated;
use Glueful\Lemma\Collections\Events\CollectionRowDeleted;
use Glueful\Lemma\Collections\Events\CollectionRowUpdated;

/**
 * Bridges the pack's pure `CollectionRow*` events to the audit log.
 *
 * The `lemma-collections` pack depends only on framework + contracts (never `glueful/audit`), so its
 * events stay pure. This App-side listener maps each to a {@see CollectionRowAuditEvent} — an
 * {@see \Glueful\Extensions\Audit\Contracts\AuditableEvent} the Audit extension records automatically
 * (resolving the actor's email label + request context), so audit recording is not re-implemented here.
 */
final class CollectionAuditListener
{
    public function __construct(private readonly EventService $events)
    {
    }

    public function __invoke(object $event): void
    {
        $audit = match (true) {
            $event instanceof CollectionRowCreated =>
                new CollectionRowAuditEvent('created', $event->collectionName, $event->rowUuid, $event->actor->id),
            $event instanceof CollectionRowUpdated =>
                new CollectionRowAuditEvent('updated', $event->collectionName, $event->rowUuid, $event->actor->id),
            $event instanceof CollectionRowDeleted =>
                new CollectionRowAuditEvent('deleted', $event->collectionName, $event->rowUuid, $event->actor->id),
            default => null,
        };

        if ($audit !== null) {
            $this->events->dispatch($audit);
        }
    }
}
