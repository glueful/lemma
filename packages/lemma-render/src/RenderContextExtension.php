<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;
use Glueful\Lemma\Contracts\Navigation\MenuReader;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The theme-facing template functions. The extension is the per-render context object:
 * the controller sets the current locale before rendering (request-scoped in classic
 * PHP — deliberately not global static state).
 *
 *   menu(slug)      → MenuReader tree for the current locale; [] when navigation is
 *                     absent or disabled (render has NO hard dependency on it)
 *   path(entryUuid) → the entry's live public path; null unless published (a template
 *                     can never emit a dead link)
 *   asset(rel)      → /theme-assets/{rel}; rejects absolute URLs, .., leading /, and
 *                     backslashes with a template-facing error naming the value
 */
final class RenderContextExtension extends AbstractExtension
{
    private string $locale;

    public function __construct(
        private readonly ?MenuReader $menus,
        private readonly EntryTargetResolver $targets,
        string $defaultLocale = 'en',
    ) {
        $this->locale = $defaultLocale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('menu', $this->menu(...)),
            new TwigFunction('path', $this->path(...)),
            new TwigFunction('asset', $this->asset(...)),
        ];
    }

    /** @return list<array{label:string,url:string,entry:?string,children:list<mixed>}> */
    public function menu(string $slug): array
    {
        return $this->menus?->menu($slug, $this->locale) ?? [];
    }

    public function path(string $entryUuid): ?string
    {
        return $this->targets->resolve($entryUuid, $this->locale)['path'];
    }

    public function asset(string $rel): string
    {
        $bad = $rel === ''
            || str_starts_with($rel, '/')
            || str_contains($rel, '\\')
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel) === 1
            || in_array('..', explode('/', $rel), true);
        if ($bad) {
            throw new RuntimeError(sprintf(
                'asset(): "%s" is not a safe theme-relative path (no absolute URLs, "..", leading "/" or "\\").',
                $rel,
            ));
        }
        return '/theme-assets/' . $rel;
    }
}
