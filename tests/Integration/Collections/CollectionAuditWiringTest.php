<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Collections\Audit\CollectionRowAuditEvent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Events\EventService;
use Glueful\Lemma\Collections\Data\Actor;
use Glueful\Lemma\Collections\Events\CollectionRowCreated;
use Glueful\Lemma\Collections\Events\CollectionRowDeleted;

/**
 * Proves the App audit listener is wired to the pack's pure CollectionRow* events: dispatching one
 * through the app EventService triggers CollectionAuditListener (registered in LemmaServiceProvider),
 * which bridges it to a CollectionRowAuditEvent — the AuditableEvent the Audit extension records.
 */
final class CollectionAuditWiringTest extends LemmaTestCase
{
    public function testCollectionRowEventsAreBridgedToAuditableEvents(): void
    {
        $events = $this->container()->get(EventService::class);

        /** @var list<CollectionRowAuditEvent> $captured */
        $captured = [];
        $events->addListener(
            CollectionRowAuditEvent::class,
            static function (CollectionRowAuditEvent $e) use (&$captured): void {
                $captured[] = $e;
            },
        );

        $events->dispatch(new CollectionRowCreated('posts', 'row-1', ['uuid' => 'row-1'], new Actor('admin', 'u-1')));
        $events->dispatch(new CollectionRowDeleted('posts', 'row-9', new Actor('api_key', 'k-2')));

        self::assertCount(2, $captured, 'Each CollectionRow* event must bridge to one CollectionRowAuditEvent');
        self::assertSame(
            ['created', 'deleted'],
            array_map(static fn (CollectionRowAuditEvent $e): string => $e->auditAction(), $captured),
        );
        self::assertSame('collections', $captured[0]->auditCategory());
        self::assertSame(['uuid' => 'u-1'], $captured[0]->auditActor());
        self::assertSame('row-9', $captured[1]->auditTarget()['uuid']);
        self::assertSame(['uuid' => 'k-2'], $captured[1]->auditActor());
    }
}
