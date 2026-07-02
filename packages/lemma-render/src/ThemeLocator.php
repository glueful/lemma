<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render;

/**
 * Resolves the active theme's filesystem paths per the spec §4 ladder:
 *   1. app theme dir missing entirely → pack default (a warning is the caller's job)
 *   2. app theme present but invalid theme.json → ThemeConfigError (loud 500)
 *   3. pack default missing/invalid → RuntimeException (broken install, hard 500)
 *   4. per-TEMPLATE fallback happens in the Twig loader: activePaths() returns the app
 *      theme first and the pack default second, so a theme may omit any template.
 * Resolution happens at construction (boot) — v1 theme changes require a restart.
 */
final class ThemeLocator
{
    /** @var array{templates: list<string>, assets: string, name: string} */
    private array $active;

    public function __construct(string $themeName, string $appThemesDir, ?string $packThemesDir = null)
    {
        $packThemesDir ??= dirname(__DIR__) . '/themes';
        $default = $packThemesDir . '/default';
        if (!is_dir($default . '/templates') || $this->readThemeJson($default) === null) {
            throw new \RuntimeException(
                'The lemma-render default theme is missing or invalid — broken install.',
            );
        }

        $appTheme = rtrim($appThemesDir, '/') . '/' . $themeName;
        $templates = [];
        $assets = $default . '/assets';
        $name = 'default';

        if ($themeName !== 'default' && is_dir($appTheme)) {
            if ($this->readThemeJson($appTheme) === null) {
                throw new ThemeConfigError(
                    "Theme \"{$themeName}\" has a missing or invalid theme.json ({$appTheme}/theme.json).",
                );
            }
            $templates[] = $appTheme . '/templates';
            $assets = $appTheme . '/assets';
            $name = $themeName;
        }
        $templates[] = $default . '/templates';

        $this->active = ['templates' => $templates, 'assets' => $assets, 'name' => $name];
    }

    /** @return array{templates: list<string>, assets: string, name: string} */
    public function activePaths(): array
    {
        return $this->active;
    }

    /** @return array<string,mixed>|null null = missing or invalid */
    private function readThemeJson(string $themeDir): ?array
    {
        $file = $themeDir . '/theme.json';
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded) || !is_string($decoded['name'] ?? null) || $decoded['name'] === '') {
            return null;
        }
        return $decoded;
    }
}
