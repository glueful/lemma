<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class ImportersCapabilityTest extends LemmaTestCase
{
    public function testImportersCapabilityIsRegisteredAndEnabled(): void
    {
        $reg = $this->container()->get(CapabilityRegistry::class);
        $ids = array_map(fn (Capability $c) => $c->id, $reg->enabled());
        self::assertContains('lemma.importers', $ids, 'the lemma-importers pack must register its capability');
    }
}
