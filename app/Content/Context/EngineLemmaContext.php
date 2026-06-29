<?php

declare(strict_types=1);

namespace App\Content\Context;

use App\Content\Localization\ContentLocaleService;
use App\Content\Seo\PathRenderer;
use App\Settings\GeneralSettings;
use Glueful\Lemma\Contracts\Context\LemmaContext;

final class EngineLemmaContext implements LemmaContext
{
    public function __construct(
        private readonly ContentLocaleService $locales,
        private readonly GeneralSettings $settings,
        private readonly PathRenderer $paths,
    ) {
    }

    public function defaultLocale(): string
    {
        return $this->locales->default();
    }

    public function enabledLocales(): array
    {
        return array_values($this->locales->enabled());
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings->all()[$key] ?? $default;
    }

    public function renderPath(string $contentTypeSlug, string $locale, string $slug): string
    {
        return $this->paths->render($contentTypeSlug, $locale, $slug);
    }
}
