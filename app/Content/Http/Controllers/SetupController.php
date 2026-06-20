<?php

declare(strict_types=1);

namespace App\Content\Http\Controllers;

use App\Content\Http\DTOs\Requests\SetupData;
use App\Setup\SetupService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * First-run web setup — UNAUTHENTICATED by design (there is no admin yet to authenticate), but
 * SELF-LOCKING: once SetupService::isInstalled() is true it returns 409 forever, so a second
 * "first" admin can never be created. The heavy lifting (and the race-safety) lives in
 * SetupService::install(), which the future `php glueful lemma:setup` CLI shares.
 *
 * Returns a Symfony JsonResponse directly; the Glueful router normalizes any returned Symfony
 * Response (there is no Response::fromSymfony bridge).
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
    public function setup(SetupData $input): JsonResponse
    {
        // Permanent lock: refuse once installed. This is the gate; install() ALSO re-checks
        // inside its transaction, so even a TOCTOU race past this point cannot double-create.
        if ($this->setup->isInstalled()) {
            return new JsonResponse(
                ['message' => 'Setup has already been completed.'],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        try {
            $this->setup->install(
                $input->site_name,
                $input->admin_email,
                $input->admin_password,
                $input->locale,
            );
        } catch (\RuntimeException) {
            return new JsonResponse(
                ['message' => 'Setup has already been completed.'],
                JsonResponse::HTTP_CONFLICT,
            );
        }

        return new JsonResponse(['message' => 'Setup complete.', 'installed' => true]);
    }
}
