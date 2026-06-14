<?php

declare(strict_types=1);

namespace App\Content\Events;

/**
 * Fired when a content type (model) is created.
 */
final class ModelCreated extends BaseModelEvent
{
    public function name(): string
    {
        return 'model.created';
    }
}
