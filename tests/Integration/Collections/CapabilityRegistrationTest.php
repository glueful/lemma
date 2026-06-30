<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class CapabilityRegistrationTest extends LemmaTestCase
{
    public function testCollectionsCapabilityIsRegisteredAndEnabled(): void
    {
        $caps = $this->container()->get(CapabilityRegistry::class);
        self::assertTrue($caps->isEnabled('lemma.collections'));
    }
}
