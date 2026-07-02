<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Navigation\MenuReader;
use Glueful\Lemma\Render\Http\Controllers\RenderController;
use Psr\Container\ContainerInterface;

use function config;

final class LemmaRenderServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            ThemeLocator::class => [
                'shared' => true,
                'factory' => [self::class, 'makeThemeLocator'],
            ],
            RenderContextExtension::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderContextExtension'],
            ],
            TwigFactory::class => [
                'shared' => true,
                'factory' => [self::class, 'makeTwigFactory'],
            ],
            ReservedPaths::class => [
                'shared' => true,
                'factory' => [self::class, 'makeReservedPaths'],
            ],
            RenderController::class => [
                'shared' => true,
                'factory' => [self::class, 'makeRenderController'],
            ],
        ];
    }

    public static function makeRenderController(ContainerInterface $container): RenderController
    {
        return new RenderController(
            $container->get(ApplicationContext::class),
            $container->get(\Glueful\Lemma\Contracts\Delivery\PublicRouteResolver::class),
            $container->get(TwigFactory::class),
            $container->get(RenderContextExtension::class),
            $container->get(ReservedPaths::class),
            $container->get(\Psr\Log\LoggerInterface::class),
        );
    }

    public static function makeThemeLocator(ContainerInterface $container): ThemeLocator
    {
        $context = $container->get(ApplicationContext::class);
        return new ThemeLocator(
            (string) config($context, 'lemma_render.theme', 'default'),
            $context->getBasePath() . '/themes',
        );
    }

    public static function makeRenderContextExtension(ContainerInterface $container): RenderContextExtension
    {
        $context = $container->get(ApplicationContext::class);
        // MenuReader is OPTIONAL — render has no hard dependency on lemma-navigation.
        $menus = $container->has(MenuReader::class) ? $container->get(MenuReader::class) : null;
        return new RenderContextExtension(
            $menus instanceof MenuReader ? $menus : null,
            $container->get(EntryTargetResolver::class),
            (string) config($context, 'i18n.default_locale', 'en'),
        );
    }

    public static function makeTwigFactory(ContainerInterface $container): TwigFactory
    {
        $context = $container->get(ApplicationContext::class);
        return new TwigFactory(
            $container->get(ThemeLocator::class),
            $container->get(RenderContextExtension::class),
            $context->getBasePath() . '/storage/cache/twig',
        );
    }

    public static function makeReservedPaths(ContainerInterface $container): ReservedPaths
    {
        $context = $container->get(ApplicationContext::class);
        return new ReservedPaths(
            array_values(array_map(strval(...), (array) config($context, 'lemma_render.reserved_prefixes', []))),
            array_values(array_map(strval(...), (array) config($context, 'lemma_render.reserved_exact', []))),
        );
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

        if ($registry->isEnabled('lemma.render')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/public-routes.php');

            // Theme assets only (never templates/theme.json). Mounted at BOOT — v1 theme
            // changes require a restart/cache rebuild (spec §4).
            $assets = app($context, ThemeLocator::class)->activePaths()['assets'];
            if (is_dir($assets)) {
                $this->serveFrontend('/theme-assets', $assets, ['spaFallback' => false]);
            }
        }
    }
}
