<?php

declare(strict_types=1);

namespace Glueful\Lemma\Importers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaImportersServiceProvider extends ServiceProvider
{
    /** @return array<string,mixed> */
    public static function services(): array
    {
        // Adapter services (tagged import_export.importer) are added in Tasks 4-5.
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // No routes/config to load; adapters are tag-discovered by glueful/import-export.
    }

    public function boot(ApplicationContext $context): void
    {
        container($context)->get(CapabilityRegistry::class)->register(
            new Capability(
                'lemma.importers',
                label: 'Content importers',
                description: 'CSV, Markdown and WordPress content/user import adapters.',
            ),
        );
    }
}
