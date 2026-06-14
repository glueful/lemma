<?php

declare(strict_types=1);

namespace App\Content\Events;

/**
 * Fired when a content entry is created.
 */
final class EntryCreated extends BaseEntryEvent
{
    public function name(): string
    {
        return 'entry.created';
    }
}
