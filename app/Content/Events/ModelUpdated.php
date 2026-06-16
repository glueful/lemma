<?php

declare(strict_types=1);

namespace App\Content\Events;

/**
 * Fired when a content type (model) schema is updated.
 */
final class ModelUpdated extends BaseModelEvent
{
    public function name(): string
    {
        return 'model.updated';
    }
}
