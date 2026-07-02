<?php

declare(strict_types=1);

namespace Glueful\Lemma\Render\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response as ApiResponse;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;
use Glueful\Lemma\Render\HomepageConfigError;
use Glueful\Lemma\Render\RenderContextExtension;
use Glueful\Lemma\Render\ReservedPaths;
use Glueful\Lemma\Render\TwigFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

use function config;

/**
 * The render pipeline: reserved-path guard → PublicRouteResolver → template hierarchy →
 * HTML. Raw Symfony responses (never the JSON envelope) EXCEPT reserved paths, which get
 * the framework's standard JSON 404 (byte-compatible with a render-less install). Render
 * exceptions try error.twig once, then a plain-text 500 — never a render loop.
 */
final class RenderController
{
    private ?Environment $twig = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PublicRouteResolver $resolver,
        private readonly TwigFactory $twigFactory,
        private readonly RenderContextExtension $extension,
        private readonly ReservedPaths $reserved,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function home(Request $request): Response
    {
        $homepageEntry = (string) config($this->context, 'lemma_render.homepage_entry', '');
        $locale = (string) config($this->context, 'i18n.default_locale', 'en');
        $entry = null;

        if ($homepageEntry !== '') {
            $result = $this->resolver->resolveEntry($homepageEntry);
            if ($result['kind'] !== 'content') {
                return $this->homepageConfigFailure($homepageEntry);
            }
            $entry = $result['content'];
            $locale = (string) $result['locale'];
        }

        // Homepage ALWAYS renders index.twig (spec §4) — the entry, when configured,
        // arrives as context; routed pages use the entry hierarchy instead.
        return $this->render('index.twig', $locale, $entry, 200);
    }

    public function page(Request $request, string $path): Response
    {
        if ($this->reserved->isReserved($path)) {
            // Byte-compatible with the router's own 404 (shape + content type); API
            // clients under /v1 etc. never receive themed HTML.
            return ApiResponse::error('Not Found', 404);
        }

        $result = $this->resolver->resolvePath('/' . ltrim($path, '/'));

        return match ($result['kind']) {
            'redirect' => new Response('', $result['redirect']['status'], [
                'Location' => $result['redirect']['location'],
            ]),
            'gone' => $this->render('error.twig', $this->defaultLocale(), null, 410),
            'content' => $this->renderEntry($result),
            default => $this->render('404.twig', $this->defaultLocale(), null, 404),
        };
    }

    /** @param array{locale: ?string, type: ?string, content: ?array} $result */
    private function renderEntry(array $result): Response
    {
        $entry = $result['content'];
        $locale = (string) $result['locale'];
        // Template hierarchy: entry/{type-slug}.twig → entry.twig (the resolver's `type`
        // field carries the content-type slug for exactly this selection).
        $typeSlug = (string) ($result['type'] ?? '');
        $candidate = $typeSlug !== '' ? "entry/{$typeSlug}.twig" : '';

        $template = $candidate !== '' && $this->twig()->getLoader()->exists($candidate)
            ? $candidate
            : 'entry.twig';
        return $this->render($template, $locale, $entry, 200);
    }

    /** @param array<string,mixed>|null $entry */
    private function render(string $template, string $locale, ?array $entry, int $status): Response
    {
        $this->extension->setLocale($locale);
        $context = [
            'site' => [
                'name' => (string) config($this->context, 'lemma_render.site_name', 'Lemma'),
                'locale' => $locale,
                'locales' => [],
            ],
        ];
        if ($entry !== null) {
            $context['entry'] = $entry;
        }

        try {
            $html = $this->twig()->render($template, $context);
        } catch (\Throwable $e) {
            $this->logger->error('lemma-render: template render failed', [
                'template' => $template,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
            if ($template === 'error.twig') {
                return new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }
            return $this->render('error.twig', $locale, null, 500);
        }

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    private function homepageConfigFailure(string $configured): Response
    {
        $error = new HomepageConfigError(
            "lemma_render.homepage_entry (\"{$configured}\") does not resolve to published, routed content.",
        );
        // Always logged; the message reaches the BODY only in debug mode (never leak in prod).
        $this->logger->error('lemma-render: ' . $error->getMessage());
        $debug = (bool) config($this->context, 'app.debug', false);
        return new Response(
            $debug ? $error->getMessage() : 'Internal Server Error',
            500,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    private function twig(): Environment
    {
        return $this->twig ??= $this->twigFactory->environment();
    }

    private function defaultLocale(): string
    {
        return (string) config($this->context, 'i18n.default_locale', 'en');
    }
}
