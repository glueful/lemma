<?php

declare(strict_types=1);

namespace App\Providers;

final class AppServiceProvider
{
    /**
     * Runtime DSL provider path (used by ProviderLocator + ServicesLoader).
     * @return array<string, array<string, mixed>>
     */
    public static function services(): array
    {
        // Application controllers are registered here. The Lemma content engine
        // wires its own services in LemmaServiceProvider.
        return [];
    }
}
