<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Serves the admin SPA's runtime config as raw JSON at the UNAUTHENTICATED
 * `GET /admin/config.json`. This must NOT sit behind the `/v1/admin` auth group: the SPA
 * fetches it at boot, before it has a token, to learn the API base. Values come from
 * `config('lemma.admin.*')` (env-overridable), so one compiled bundle works across installs.
 *
 * Returns a bare JSON object (NOT the framework `data`-envelope) because the SPA reads
 * `apiBase`/`sitePreviewUrl`/`defaultLocale` at the top level — a plain config document.
 * Returns a Symfony `JsonResponse` directly; the Glueful router accepts any Symfony
 * `Response` return (`Glueful\Http\Response` extends it), so no envelope/bridge is needed.
 */
final class AdminConfigController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function config(): JsonResponse
    {
        $payload = [
            'apiBase' => (string) config($this->context, 'lemma.admin.api_base', '/v1/admin'),
            'sitePreviewUrl' => (string) config($this->context, 'lemma.admin.site_preview_url', ''),
            'defaultLocale' => (string) config($this->context, 'lemma.admin.default_locale', 'en'),
        ];

        // No-store: install config can change without a rebuild; the SPA must read it fresh.
        $json = new JsonResponse($payload);
        $json->headers->set('Cache-Control', 'no-store');
        return $json;
    }
}
