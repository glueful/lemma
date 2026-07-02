<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\EventService;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Analytics\Facts\ActorHasher;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;
use Glueful\Lemma\Analytics\Http\Controllers\AnalyticsController;
use Glueful\Lemma\Analytics\Console\PruneAnalyticsCommand;
use Glueful\Lemma\Analytics\Listeners\AuthAnalyticsListener;
use Glueful\Lemma\Analytics\Query\AnalyticsQuery;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class LemmaAnalyticsServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            ActorHasher::class => [
                'shared'  => true,
                'factory' => [self::class, 'makeActorHasher'],
            ],
            AnalyticsRecorder::class => [
                'class'    => AnalyticsRecorder::class,
                'shared'   => true,
                'autowire' => true,
            ],
            AuthAnalyticsListener::class => [
                'class'    => AuthAnalyticsListener::class,
                'shared'   => true,
                'autowire' => true,
            ],
            AnalyticsQuery::class => [
                'class'    => AnalyticsQuery::class,
                'shared'   => true,
                'autowire' => true,
            ],
            AnalyticsController::class => [
                'class'    => AnalyticsController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            PruneAnalyticsCommand::class => [
                'class'    => PruneAnalyticsCommand::class,
                'shared'   => true,
                'autowire' => true,
            ],
        ];
    }

    public static function makeActorHasher(ContainerInterface $container): ActorHasher
    {
        $context = $container->get(ApplicationContext::class);
        $key = (string) config($context, 'analytics.hash_key', '');
        if ($key === '') {
            // Warn, don't throw: the hasher is resolved during boot (via the auth listener), and
            // analytics must stay best-effort. An empty key still yields a one-way digest — it is
            // just unsalted, so equal actor ids hash identically across instances.
            $container->get(LoggerInterface::class)->warning(
                'analytics.hash_key is empty (ANALYTICS_HASH_KEY and APP_KEY both unset); '
                . 'actor hashes are unsalted.'
            );
        }
        return new ActorHasher($key);
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded by the app config system — merge under 'analytics'
        // (the standard extension pattern, cf. extensions/audit/src/AuditServiceProvider.php:98).
        $this->mergeConfig('analytics', require __DIR__ . '/../config/analytics.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.analytics',
            label: 'Analytics',
            description: 'Product-analytics fact store fed by lifecycle events.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-analytics',
        );

        if ($registry->isEnabled('lemma.analytics')) {
            $events = app($context, EventService::class);
            $listener = app($context, AuthAnalyticsListener::class);
            $events->addListener(SessionCreatedEvent::class, [$listener, 'onLogin']);
            $events->addListener(SessionDestroyedEvent::class, [$listener, 'onLogout']);
            $events->addListener(AuthenticationFailedEvent::class, [$listener, 'onLoginFailed']);

            $this->loadRoutesFrom(__DIR__ . '/../routes/admin-routes.php');
        }

        $this->commands([PruneAnalyticsCommand::class]);
    }
}
