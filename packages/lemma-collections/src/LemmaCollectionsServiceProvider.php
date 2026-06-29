<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Collections\CollectionManager;
use Glueful\Lemma\Collections\Data\RowRepository;
use Glueful\Lemma\Collections\Data\RowValidator;
use Glueful\Lemma\Collections\Http\ActorResolver;
use Glueful\Lemma\Collections\Http\CollectionScopeMiddleware;
use Glueful\Lemma\Collections\Http\Controllers\CollectionAdminSchemaController;
use Glueful\Lemma\Collections\Http\Controllers\CollectionDataController;
use Glueful\Lemma\Collections\Query\QueryCompiler;
use Glueful\Lemma\Collections\Relations\RelationResolver;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;
use Glueful\Lemma\Collections\Schema\CollectionFieldTypes;
use Glueful\Lemma\Collections\Schema\ColumnMapper;
use Glueful\Lemma\Collections\Schema\DdlPlanner;
use Glueful\Lemma\Collections\Schema\SchemaMaterializer;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;

final class LemmaCollectionsServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            ColumnMapper::class => [
                'class'    => ColumnMapper::class,
                'shared'   => true,
                'autowire' => true,
            ],
            DdlPlanner::class => [
                'class'    => DdlPlanner::class,
                'shared'   => true,
                'autowire' => true,
            ],
            SchemaMaterializer::class => [
                'class'    => SchemaMaterializer::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionDefinitionRepository::class => [
                'class'    => CollectionDefinitionRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionManager::class => [
                'class'    => CollectionManager::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RowValidator::class => [
                'class'    => RowValidator::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RelationResolver::class => [
                'class'    => RelationResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            RowRepository::class => [
                'class'    => RowRepository::class,
                'shared'   => true,
                'autowire' => true,
            ],
            QueryCompiler::class => [
                'class'    => QueryCompiler::class,
                'shared'   => true,
                'autowire' => true,
            ],
            ActorResolver::class => [
                'class'    => ActorResolver::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionScopeMiddleware::class => [
                'class'    => CollectionScopeMiddleware::class,
                'shared'   => true,
                'autowire' => true,
                'alias'    => ['collection_scope'],
            ],
            CollectionDataController::class => [
                'class'    => CollectionDataController::class,
                'shared'   => true,
                'autowire' => true,
            ],
            CollectionAdminSchemaController::class => [
                'class'    => CollectionAdminSchemaController::class,
                'shared'   => true,
                'autowire' => true,
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // No-op: migrations are loaded in boot() (the framework extension convention —
        // cf. aegis/users/import-export); DI bindings are declared via services().
    }

    public function boot(ApplicationContext $context): void
    {
        app($context, CapabilityRegistry::class)->register(new Capability(
            'lemma.collections',
            label: 'Data collections',
            description: 'Developer-defined data collections with a public CRUD/query API.',
        ));

        CollectionFieldTypes::register(app($context, FieldTypeRegistry::class));

        // Migrations register on INSTALL, not enable (outside the gate below), so disabling
        // the capability still preserves the tables.
        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-collections',
        );

        // Routes are gated by ENABLED state (spec §5): register the public API only when the
        // capability is on. Disabling lemma.collections leaves migrations/tables intact but removes
        // the public surface entirely — requests 404 rather than reaching a disabled handler.
        if (app($context, CapabilityRegistry::class)->isEnabled('lemma.collections')) {
            $this->loadRoutesFrom(__DIR__ . '/Http/routes.php'); // file added in Task 11
        }
    }
}
