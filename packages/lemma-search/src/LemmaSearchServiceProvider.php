<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaSearchServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's own tree under 'lemma_search'.
        $this->mergeConfig('lemma_search', require __DIR__ . '/../config/lemma-search.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.search',
            label: 'Search',
            description: 'Public, delivery-parity content search backed by Meilisearch.',
        ));
    }
}
