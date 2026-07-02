<?php

declare(strict_types=1);

namespace Glueful\Lemma\Navigation\Events;

use Glueful\Events\Contracts\BaseEvent;

/** A menu (or its tree) was created/renamed/replaced/deleted. Render-cache purge seam. */
final class MenuUpdated extends BaseEvent
{
    public function __construct(public readonly string $menuSlug)
    {
        parent::__construct();
    }
}
