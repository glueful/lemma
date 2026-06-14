<?php

declare(strict_types=1);

namespace App\Content\Events;

/**
 * Fired when a content entry is unpublished.
 */
final class EntryUnpublished extends BaseEntryEvent
{
    public function name(): string
    {
        return 'entry.unpublished';
    }
}
