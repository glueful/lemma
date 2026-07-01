<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;

final class LemmaAnalyticsServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [];
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded by the app config system — merge under 'analytics'
        // (the standard extension pattern, cf. extensions/audit/src/AuditServiceProvider.php:98).
        $this->mergeConfig('analytics', require __DIR__ . '/../config/analytics.php');
    }

    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            'lemma.analytics',
            label: 'Analytics',
            description: 'Product-analytics fact store fed by lifecycle events.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-analytics',
        );
    }
}
