<?php

declare(strict_types=1);

namespace Glueful\Lemma\Importers\Concerns;

use Glueful\Http\Exceptions\Client\ForbiddenException;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

trait RequiresImportersCapability
{
    private function assertImportersEnabled(CapabilityRegistry $capabilities): void
    {
        if (!$capabilities->isEnabled('lemma.importers')) {
            throw new ForbiddenException('The lemma.importers capability is disabled.');
        }
    }
}
