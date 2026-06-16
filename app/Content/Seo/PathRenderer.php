<?php

declare(strict_types=1);

namespace App\Content\Seo;

final class PathRenderer
{
    public function __construct(
        private readonly string $routeTemplate = '/{locale}/{type}/{slug}',
        private readonly ?string $publicUrlBase = null,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    public function render(string $contentTypeSlug, string $locale, string $slug): string
    {
        return $this->withBase($this->renderTemplate($this->routeTemplate, $contentTypeSlug, $locale, $slug));
    }

    public function renderDefaultLocale(string $contentTypeSlug, string $slug): string
    {
        $template = preg_replace('#/?\{locale\}/?#', '/', $this->routeTemplate) ?? $this->routeTemplate;
        return $this->withBase($this->renderTemplate($template, $contentTypeSlug, $this->defaultLocale, $slug));
    }

    private function renderTemplate(string $template, string $contentTypeSlug, string $locale, string $slug): string
    {
        $path = strtr($template, [
            '{type}' => rawurlencode($contentTypeSlug),
            '{locale}' => rawurlencode($locale),
            '{slug}' => rawurlencode($slug),
        ]);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : $path;
    }

    private function withBase(string $path): string
    {
        if ($this->publicUrlBase === null || $this->publicUrlBase === '') {
            return $path;
        }

        return rtrim($this->publicUrlBase, '/') . $path;
    }
}
