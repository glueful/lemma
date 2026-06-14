<?php

declare(strict_types=1);

namespace App\Providers;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\FilterCompiler;
use App\Content\Delivery\ReferenceResolver;
use App\Content\Delivery\SortCompiler;
use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\Controllers\DeliveryController;
use App\Content\Http\Controllers\EntryController;
use App\Content\Http\Controllers\PublicationController;
use App\Content\Http\DeliveryEtag;
use App\Content\Http\RequireContentScope;
use App\Content\Http\RequireLemmaPermission;
use App\Content\Pipeline\PublishEventEmitter;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Support\FieldSelection\Projector;

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
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
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
                'autowire' => true,
            ],
            FieldValidator::class => [
                'class' => FieldValidator::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishEventEmitter::class => [
                'class' => PublishEventEmitter::class,
                'shared' => true,
                'autowire' => true,
            ],
            PublishService::class => [
                'class' => PublishService::class,
                'shared' => true,
                'autowire' => true,
            ],
            ContentTypeController::class => [
                'class' => ContentTypeController::class,
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
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: config/lemma.php is auto-loaded by the app config system, and DI
        // bindings are contributed declaratively via services(). Kept for lifecycle
        // symmetry and as the seam for future runtime registration.
    }

    public function boot(ApplicationContext $context): void
    {
        // No-op: routes/lemma_admin.php is auto-discovered by RouteManifest. Do NOT
        // call loadRoutesFrom() here — it would double-register the routes and the
        // Router throws on duplicate static paths.
    }
}
