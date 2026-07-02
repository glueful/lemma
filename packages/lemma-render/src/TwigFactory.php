<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the render Twig environment: active-theme-first loader (per-template fallback
 * to the pack default), autoescape html, filesystem compile cache with auto_reload
 * (recompiles on template mtime change — zero-friction theme development).
 */
final class TwigFactory
{
    public function __construct(
        private readonly ThemeLocator $themes,
        private readonly RenderContextExtension $extension,
        private readonly string $cacheDir,
    ) {
    }

    public function environment(): Environment
    {
        $paths = $this->themes->activePaths();
        $twig = new Environment(new FilesystemLoader($paths['templates']), [
            'autoescape' => 'html',
            'cache' => rtrim($this->cacheDir, '/') . '/' . $paths['name'],
            'auto_reload' => true,
            'strict_variables' => false,
        ]);
        $twig->addExtension($this->extension);
        return $twig;
    }
}
