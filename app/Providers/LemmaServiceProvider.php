<?php

declare(strict_types=1);

namespace App\Providers;

use App\Setup\SetupService;
use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Console\PruneVersionsCommand;
use App\Content\Console\ResyncCommand;
use App\Content\Console\RunBackfillCommand;
use App\Content\Console\RunDueSchedulesCommand;
use App\Setup\Console\CreateAdminCommand;
use App\Setup\Console\DoctorCommand;
use App\Setup\Console\ProvisionCommand;
use App\Content\Backfill\BackfillRunner;
use App\Http\Controllers\AdminConfigController;
use App\Http\Controllers\UserAdminController;
use App\Content\Http\Controllers\ContentTypeController;
use App\Http\Controllers\SetupController;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\MigrationController;
use App\Content\Http\Controllers\PreviewController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\Controllers\RedirectController;
use App\Content\Http\Controllers\ScheduleController;
use App\Content\ImportExport\LemmaContentExporter;
use App\Content\ImportExport\LemmaContentImporter;
use App\Content\Http\DeliveryEtag;
use App\Content\Events\EntryCreated;
use App\Content\Events\EntryDeleted;
use App\Content\Events\EntryPublished;
use App\Content\Events\EntryUnpublished;
use App\Content\Events\EntryUpdated;
use App\Content\Events\ModelCreated;
use App\Content\Events\ModelDeleted;
use App\Content\Events\ModelUpdated;
use App\Content\Http\DeliveryAccessMiddleware;
use App\Content\Http\OptionalApiKeyAuthMiddleware;
use App\Content\Http\RequireContentScope;
use App\Content\Http\RequireLemmaPermission;
use App\Content\Localization\ContentLocaleService;
use App\Content\Events\AssetAttached;
use App\Content\Events\AssetDetached;
use App\Content\Pipeline\Listeners\DispatchWebhookListener;
use App\Content\Pipeline\Listeners\InvalidateCacheTagsListener;
use App\Content\Pipeline\Listeners\PurgeCdnListener;
use App\Content\Pipeline\Listeners\ReindexSearchListener;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Preview\PreviewMinter;
use App\Content\Preview\PreviewReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\MigrationRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Retention\VersionPruner;
use App\Content\Schema\Migration\SchemaProjector;
use App\Content\Scheduling\ScheduleRunner;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Content\Seo\RedirectRepository;
use App\Content\Seo\RouteResolver;
use App\Content\Services\MigrationService;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Events\EventService;
use Glueful\Extensions\ServiceProvider;
use Glueful\Support\FieldSelection\Projector;
use Psr\Container\ContainerInterface;

/**
 * Wires the Lemma content engine into the application container.
 *
 * Registered in config/serviceproviders.php. The framework's ProviderClassResolver
 * folds app providers into the same provider list as composer extensions, so this
 * provider's services() are collected by the ContainerFactory and its register()/boot()
 * lifecycle is run by the ExtensionManager (it extends ServiceProvider, the gate
 * ExtensionManager::addProvider() requires).
 *
 * services() registers, with autowiring:
 *   - the four repositories (each resolves Connection),
 *   - FieldValidator,
 *   - PublishService (resolves ApplicationContext + repos + validator — all container-known),
 *   - the three admin controllers (EntryController also resolves ApplicationContext),
 *   - RequireLemmaPermission under the `lemma_permission` container alias, which is how
 *     `->middleware('lemma_permission:...')` resolves (Router::resolveMiddleware() does
 *     container->get('lemma_permission')).
 *
 * Routes: routes/lemma_admin.php is NOT loaded here. The framework's RouteManifest
 * auto-discovers every routes/*.php file (underscore-prefixed partials excepted) during
 * the HTTP phase, which already runs AFTER extension boot(). Calling loadRoutesFrom()
 * in boot() would load the file a second time and the Router throws LogicException on a
 * duplicate static route. Auto-discovery is the framework's real mechanism for app routes.
 *
 * Config: config/lemma.php lives in the app config directory and is loaded by the
 * file-based config system, so it is already available as config('lemma.*'); mergeConfig
 * is therefore unnecessary (it would only re-supply the same values as defaults).
 */
final class LemmaServiceProvider extends ServiceProvider
{
    /**
     * Guards registerEventListeners() against a double-run. EventService::addListener
     * APPENDS with no dedup, so a second registration would make every listener fire
     * twice — harmless for idempotent cache invalidation, but a real bug for webhooks
     * (duplicate deliveries). ExtensionManager::boot() already guards each provider's
     * boot() to once per app lifecycle; this flag is cheap defence-in-depth on top.
     */
    private bool $listenersRegistered = false;

    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            SetupService::class => [
                'class'    => SetupService::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ContentTypeRepository::class => [
                'class' => ContentTypeRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            EntryRepository::class => [
                'class' => EntryRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            VersionRepository::class => [
                'class' => VersionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            RouteRepository::class => [
                'class' => RouteRepository::class,
                'shared' => true,
                'arguments' => ['@' . Connection::class, '@' . RedirectRepository::class],
            ],
            RedirectRepository::class => [
                'class' => RedirectRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            PathRenderer::class => [
                'factory' => [self::class, 'makePathRenderer'],
                'shared' => true,
            ],
            RouteResolver::class => [
                'class' => RouteResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            CanonicalProjector::class => [
                'factory' => [self::class, 'makeCanonicalProjector'],
                'shared' => true,
            ],
            ReferenceProjectionRepository::class => [
                'class' => ReferenceProjectionRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationRepository::class => [
                'class' => MigrationRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleRepository::class => [
                'class' => ScheduleRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            SchemaProjector::class => [
                'class' => SchemaProjector::class,
                'shared' => false,
                'autowire' => true,
            ],
            FieldValidator::class => [
                'class' => FieldValidator::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentLocaleService::class => [
                'class' => ContentLocaleService::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishEventEmitter::class => [
                'class' => PublishEventEmitter::class,
                'shared' => true,
                'autowire' => true,
            ],
            InvalidateCacheTagsListener::class => [
                'class' => InvalidateCacheTagsListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            DispatchWebhookListener::class => [
                'class' => DispatchWebhookListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            PurgeCdnListener::class => [
                'class' => PurgeCdnListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReindexSearchListener::class => [
                'class' => ReindexSearchListener::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishService::class => [
                'class' => PublishService::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationService::class => [
                'class' => MigrationService::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentTypeController::class => [
                'class' => ContentTypeController::class,
                'shared' => true,
                'autowire' => true,
            ],
            MigrationController::class => [
                'class' => MigrationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            AdminConfigController::class => [
                'class' => AdminConfigController::class,
                'shared' => true,
                'autowire' => true,
            ],
            UserAdminController::class => [
                'class' => UserAdminController::class,
                'shared' => true,
                'autowire' => true,
            ],
            SetupController::class => [
                'class' => SetupController::class,
                'shared' => true,
                'autowire' => true,
            ],
            EntryController::class => [
                'class' => EntryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublicationController::class => [
                'class' => PublicationController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RedirectController::class => [
                'class' => RedirectController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RequireLemmaPermission::class => [
                'class' => RequireLemmaPermission::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['lemma_permission'],
            ],

            // Delivery API (published-only read path).
            DeliveryRepository::class => [
                'class' => DeliveryRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            FilterCompiler::class => [
                'class' => FilterCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
            SortCompiler::class => [
                'class' => SortCompiler::class,
                'shared' => true,
                'autowire' => true,
            ],
            ReferenceResolver::class => [
                'class' => ReferenceResolver::class,
                'shared' => true,
                'autowire' => true,
            ],
            Projector::class => [
                'class' => Projector::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryEtag::class => [
                'class' => DeliveryEtag::class,
                'shared' => true,
                'autowire' => true,
            ],
            DeliveryController::class => [
                'class' => DeliveryController::class,
                'shared' => true,
                'autowire' => true,
            ],
            RequireContentScope::class => [
                'class' => RequireContentScope::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['require_content_scope'],
            ],
            DeliveryAccessMiddleware::class => [
                'class' => DeliveryAccessMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['lemma_delivery_access'],
            ],
            LemmaContentExporter::class => [
                'class' => LemmaContentExporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.exporter'],
            ],
            LemmaContentImporter::class => [
                'class' => LemmaContentImporter::class,
                'shared' => true,
                'autowire' => true,
                'tags' => ['import_export.importer'],
            ],

            OptionalApiKeyAuthMiddleware::class => [
                'class' => OptionalApiKeyAuthMiddleware::class,
                'shared' => true,
                'autowire' => true,
                'alias' => ['optional_api_key'],
            ],

            // Preview (the narrow draft door). Minter + reader derive the same APP_KEY
            // signing key; the controller wires the admin mint + public token read.
            PreviewMinter::class => [
                'class' => PreviewMinter::class,
                'shared' => true,
                'autowire' => true,
            ],
            PreviewReader::class => [
                'class' => PreviewReader::class,
                'shared' => true,
                'autowire' => true,
            ],
            PreviewController::class => [
                'class' => PreviewController::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleController::class => [
                'class' => ScheduleController::class,
                'shared' => true,
                'autowire' => true,
            ],
            VersionPruner::class => [
                'class' => VersionPruner::class,
                'shared' => true,
                'autowire' => true,
            ],
            BackfillRunner::class => [
                'class' => BackfillRunner::class,
                'shared' => true,
                'autowire' => true,
            ],
            ScheduleRunner::class => [
                'class' => ScheduleRunner::class,
                'shared' => true,
                'autowire' => true,
            ],

            // Console command (resolved by commands() in boot()). Autowire fills its
            // BaseCommand (ContainerInterface, ApplicationContext) constructor.
            ResyncCommand::class => [
                'class' => ResyncCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            PruneVersionsCommand::class => [
                'class' => PruneVersionsCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunBackfillCommand::class => [
                'class' => RunBackfillCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            RunDueSchedulesCommand::class => [
                'class' => RunDueSchedulesCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            DoctorCommand::class => [
                'class' => DoctorCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            ProvisionCommand::class => [
                'class' => ProvisionCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
            CreateAdminCommand::class => [
                'class' => CreateAdminCommand::class,
                'shared' => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: config/lemma.php is auto-loaded by the app config system, and DI
        // bindings are contributed declaratively via services(). Kept for lifecycle
        // symmetry and as the seam for future runtime registration.
    }

    public static function makePathRenderer(ContainerInterface $container): PathRenderer
    {
        $context = $container->get(ApplicationContext::class);

        return new PathRenderer(
            (string) config($context, 'lemma.seo.route_template', '/{locale}/{type}/{slug}'),
            config($context, 'lemma.seo.public_url_base') === null
                ? null
                : (string) config($context, 'lemma.seo.public_url_base'),
            (string) config($context, 'i18n.default_locale', 'en')
        );
    }

    public static function makeCanonicalProjector(ContainerInterface $container): CanonicalProjector
    {
        return new CanonicalProjector(
            $container->get(DeliveryRepository::class),
            $container->get(RouteRepository::class),
            $container->get(ContentTypeRepository::class),
            $container->get(PathRenderer::class),
            (string) config($container->get(ApplicationContext::class), 'i18n.default_locale', 'en')
        );
    }

    public function boot(ApplicationContext $context): void
    {
        // Routes: routes/lemma_admin.php is auto-discovered by RouteManifest. Do NOT
        // call loadRoutesFrom() here — it would double-register the routes and the
        // Router throws on duplicate static paths.

        // These seed rows depend on Aegis' RBAC tables, whose extension migrations run
        // at DEPENDENT priority. Register the seeder in the same tier so it runs after
        // Aegis' lower-numbered migrations instead of before them as an app migration.
        $this->loadMigrationsFrom(
            dirname(__DIR__, 2) . '/database/dependent-migrations',
            MigrationPriority::DEPENDENT,
            'app:dependent'
        );

        // Mount the compiled admin SPA at /admin via the framework seam: secure asset serving
        // + index.html deep-link fallback + cache split. No-ops (with a warning) if the bundle
        // is unbuilt. The /admin/config + /admin/setup static routes (routes/lemma_admin_spa.php)
        // keep precedence over the SPA catch-all via the router's static-first lookup.
        // Gated by lemma.admin.enabled so an operator can disable the default admin and bring
        // their own (the admin is a replaceable client of the /v1/admin API).
        if ((bool) config($context, 'lemma.admin.enabled', true)) {
            $this->serveFrontend(
                '/admin',
                (string) config($context, 'lemma.admin.bundle_path', dirname(__DIR__, 2) . '/public/admin'),
                ['name' => 'Lemma Admin'],
            );
        }

        $this->registerEventListeners($context);

        // Console: register Lemma's app commands. commands() is a console-only no-op in
        // the HTTP phase (runningInConsole() guards it), so this is free during requests.
        $this->commands([
            ResyncCommand::class,
            PruneVersionsCommand::class,
            RunBackfillCommand::class,
            RunDueSchedulesCommand::class,
            DoctorCommand::class,
            ProvisionCommand::class,
            CreateAdminCommand::class,
        ]);
    }

    /**
     * Wire content-pipeline listeners onto the PSR-14 EventService.
     *
     * Listeners are registered lazily by service id ('@' . Listener::class): the
     * dispatcher resolves them from the container on first dispatch and invokes them as
     * callables (so each listener exposes __invoke($event)). This is the shared pattern
     * for every pipeline listener — extend $listeners with [eventClass => [...listeners]].
     */
    private function registerEventListeners(ApplicationContext $context): void
    {
        // addListener() appends with no dedup, so re-running this would double-fire every
        // listener — duplicate webhook deliveries. Refuse to register twice.
        if ($this->listenersRegistered) {
            return;
        }
        $this->listenersRegistered = true;

        $events = app($context, EventService::class);

        // event class => list of listener service ids (lazy '@' form).
        //
        // PurgeCdnListener and ReindexSearchListener are CAPABILITY-GATED no-ops in a lean
        // install (no glueful/cdn / content reindexer): they self-skip at invocation, so wiring
        // them broadly is safe. PurgeCdnListener mirrors the cache listener's tag scope (entry
        // + model events, since both move lemma:type:{slug}). ReindexSearchListener is wired to
        // entry LIFECYCLE events only (publish/unpublish/update/delete) — the ones that change a
        // single entry's published index document; model/asset events don't.
        $listeners = [
            // Cache-tag invalidation (V1_DESIGN §5). Entry events drop the entry + type
            // tags; model events drop the type tag.
            EntryPublished::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryUnpublished::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryDeleted::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryUpdated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
                ReindexSearchListener::class,
            ],
            EntryCreated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelCreated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelUpdated::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            ModelDeleted::class => [
                InvalidateCacheTagsListener::class,
                DispatchWebhookListener::class,
                PurgeCdnListener::class,
            ],
            // Asset delta events (V1_DESIGN §8) are meaningful to external receivers
            // ("where is this asset used") but carry no cache tags — webhook only.
            AssetAttached::class => [DispatchWebhookListener::class],
            AssetDetached::class => [DispatchWebhookListener::class],
        ];

        foreach ($listeners as $eventClass => $serviceIds) {
            foreach ($serviceIds as $serviceId) {
                $events->addListener($eventClass, '@' . $serviceId);
            }
        }
    }
}
