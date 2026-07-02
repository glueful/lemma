<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaRenderServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's tree under 'lemma_render'.
        $this->mergeConfig('lemma_render', require __DIR__ . '/../config/lemma-render.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.render',
            label: 'Rendered delivery',
            description: 'Server-rendered pages from published content via filesystem Twig themes.',
        ));
    }
}
