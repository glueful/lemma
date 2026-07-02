<?php

declare(strict_types=1);

namespace App\Tests\Integration\Navigation;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class NavigationCapabilityTest extends LemmaTestCase
{
    public function testCapabilityRegisteredAndEnabledByDefault(): void
    {
        self::assertTrue(
            $this->container()->get(CapabilityRegistry::class)->isEnabled('lemma.navigation'),
            'lemma.navigation must be registered and enabled by default',
        );
    }
}
