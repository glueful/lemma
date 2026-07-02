<?php

declare(strict_types=1);

namespace Glueful\Lemma\Workflow;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Permissions\PermissionManager;
use Psr\Container\ContainerInterface;

final class LemmaWorkflowServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            WorkflowStateRepository::class => [
                'class' => WorkflowStateRepository::class, 'shared' => true, 'autowire' => true,
            ],
            WorkflowService::class => [
                'class' => WorkflowService::class, 'shared' => true, 'autowire' => true,
            ],
            WorkflowPublishGate::class => [
                'shared' => true,
                'factory' => [self::class, 'makeWorkflowPublishGate'],
                'tags' => ['lemma.publish_gate'],
            ],
        ];
    }

    public static function makeWorkflowPublishGate(ContainerInterface $container): WorkflowPublishGate
    {
        // Dual-id PermissionManager lookup — the RequireLemmaPermission convention.
        $permissions = null;
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            if ($container->has($id) && ($m = $container->get($id)) instanceof PermissionManager) {
                $permissions = $m;
                break;
            }
        }
        return new WorkflowPublishGate(
            $container->get(CapabilityRegistry::class),
            $container->get(WorkflowStateRepository::class),
            $permissions,
        );
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's tree under 'lemma_workflow'.
        $this->mergeConfig('lemma_workflow', require __DIR__ . '/../config/lemma-workflow.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.workflow',
            label: 'Approval workflow',
            description: 'Single-stage editorial review over draft/publish.',
        ));

        $this->loadMigrationsFrom(
            __DIR__ . '/../migrations',
            MigrationPriority::DEPENDENT,
            'lemma-workflow',
        );
    }
}
