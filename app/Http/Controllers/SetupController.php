<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\Http\DTOs\Requests\SetupData;
use App\Setup\SetupService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Installer\EnvWriter;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * First-run web setup — UNAUTHENTICATED by design (there is no admin yet to authenticate), but
 * SELF-LOCKING: once SetupService::isInstalled() is true it returns 409 forever, so a second
 * "first" admin can never be created. The heavy lifting (and the race-safety) lives in
 * SetupService::install(), which the future `php glueful lemma:setup` CLI shares.
 *
 * Responses use the framework's standard envelope via Glueful\Http\Response (success / error),
 * matching the rest of the API.
 *
 * On a successful install it also records the request's origin as BASE_URL in .env (silent
 * overwrite) so the install's canonical URL is captured without manual entry. This is HTTP-only
 * (the CLI install path has no request); see persistBaseUrl().
 */
final class SetupController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SetupService $setup,
    ) {
    }

    #[ApiOperation(
        summary: 'First-run web setup',
        description: 'Unauthenticated, self-locking first-run setup: creates the first admin and '
            . 'writes site settings. Returns 409 forever once the instance is installed — a second '
            . '"first" admin can never be created.',
        tags: ['Lemma Setup'],
    )]
    #[ApiResponse(200, description: 'Setup complete; the first admin was created.')]
    #[ApiResponse(409, description: 'Already installed — setup is permanently locked.')]
    #[ApiResponse(422, description: 'Invalid setup payload (site name, admin email/password, locale).')]
    public function setup(SetupData $input, ?Request $request = null): Response
    {
        // Permanent lock: refuse once installed. This is the gate; install() ALSO re-checks
        // inside its transaction, so even a TOCTOU race past this point cannot double-create.
        if ($this->setup->isInstalled()) {
            return Response::error('Setup has already been completed.', 409);
        }

        try {
            $this->setup->install(
                $input->site_name,
                $input->admin_email,
                $input->admin_password,
                $input->locale,
            );
        } catch (\RuntimeException) {
            return Response::error('Setup has already been completed.', 409);
        }

        // Record the URL this instance was set up on as its canonical BASE_URL (used for absolute
        // links: docs server, CDN, emails, signed URLs). Setup is operator-initiated on the real
        // host, so deriving it from the request here is safe (unlike per-request URL generation).
        // The router always injects $request over HTTP; it's null only for direct/CLI invocation
        // (no request to derive from), which skips the write.
        if ($request !== null) {
            $this->persistBaseUrl($request->getSchemeAndHttpHost());
        }

        return Response::success(['installed' => true], 'Setup complete.');
    }

    /**
     * Write the detected origin to BASE_URL in .env (silent overwrite). Non-fatal: setup has already
     * succeeded, so a failed write (e.g. read-only .env) only means the operator sets it by hand.
     */
    private function persistBaseUrl(string $baseUrl): void
    {
        try {
            (new EnvWriter(base_path($this->context, '.env')))->set('BASE_URL', $baseUrl);
        } catch (\Throwable $e) {
            error_log('Setup: failed to persist BASE_URL: ' . $e->getMessage());
        }
    }
}
