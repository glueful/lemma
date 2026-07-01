<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class SeoMetaEndpointTest extends LemmaTestCase
{
    public function testCapabilityAndTableExist(): void
    {
        // CapabilityRegistry declares register()/all()/enabled()/isEnabled() — no find().
        // The pack is enabled by default in the test env (config/extensions.php), so
        // isEnabled() being true also proves it was registered.
        $registry = $this->container()->get(CapabilityRegistry::class);
        self::assertTrue($registry->isEnabled('lemma.seo'), 'lemma.seo registered + enabled');

        $table = $this->connection()->getPDO()
            ->query("SELECT to_regclass('public.seo_meta')")->fetchColumn();
        self::assertNotNull($table, 'seo_meta table exists after migrations');
    }
}
