<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Context\LemmaContext;

final class LemmaContextContractTest extends LemmaTestCase
{
    public function testContextResolvesAndExposesLocaleAndSettings(): void
    {
        $ctx = $this->container()->get(LemmaContext::class);
        self::assertInstanceOf(LemmaContext::class, $ctx);

        self::assertNotSame('', $ctx->defaultLocale());
        self::assertContains($ctx->defaultLocale(), $ctx->enabledLocales());

        // Unknown setting returns the provided default.
        self::assertSame('fallback', $ctx->setting('definitely.missing.key', 'fallback'));

        // Path rendering delegates to the SEO PathRenderer.
        self::assertStringContainsString('post', $ctx->renderPath('post', $ctx->defaultLocale(), 'hello'));
    }
}
