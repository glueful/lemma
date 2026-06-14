<?php

declare(strict_types=1);

namespace App\Content\Pipeline;

use App\Content\Events\BaseContentEvent;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;

/**
 * Dispatches a primary content domain event bound to the current transaction's
 * commit (V1_DESIGN §5).
 *
 * The single contract: each content mutation emits exactly ONE primary PSR-14
 * event, and it must fire on the OUTERMOST commit only — never on a rollback.
 * {@see emitAfterCommit()} registers the dispatch through
 * {@see Connection::afterCommit()}, whose semantics are:
 *
 *  - In a transaction → the callback is queued at the current level, promoted to
 *    the parent on a nested (savepoint) commit, and discarded on rollback. It
 *    fires once, when the outermost transaction commits.
 *  - NOT in a transaction → the callback runs immediately. (Callers that own a
 *    `db()->transaction()` therefore call this right AFTER that block returns:
 *    if there is no enclosing transaction the commit has already happened and an
 *    immediate dispatch is correct; if an outer transaction is still active the
 *    dispatch is bound to it and fires on its commit.)
 */
final class PublishEventEmitter
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EventService $events,
    ) {
    }

    public function emitAfterCommit(BaseContentEvent $event): void
    {
        db($this->context)->afterCommit(fn() => $this->events->dispatch($event));
    }
}
