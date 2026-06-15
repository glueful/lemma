<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\DTOs\StatusData;
use App\Http\DTOs\WelcomeData;
use Glueful\Controllers\BaseController;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The skeleton's two unauthenticated smoke-test endpoints. Both return a typed DTO (which the
 * framework serializes into the standard {@see \Glueful\Http\Response} envelope) rather than a
 * Response directly, so they double as a worked example of the DTO-return controller style and
 * as a quick "is the app wired up?" check.
 */
class WelcomeController extends BaseController
{
    /**
     * The welcome payload — a greeting plus the app version (from `app.version`) and the
     * current timestamp — used to confirm the API boots and resolves config.
     */
    #[ApiOperation(
        summary: 'Welcome Endpoint',
        description: 'Returns a welcome payload with version and timestamp.',
        tags: ['Example'],
    )]
    #[ApiResponse(200, WelcomeData::class, description: 'Welcome payload')]
    public function index(Request $request): WelcomeData
    {
        return new WelcomeData(
            message: 'Welcome to your Glueful API!',
            version: (string) config($this->getContext(), 'app.version', '1.0.0'),
            timestamp: date('c'),
        );
    }

    /**
     * A lightweight liveness check: always reports "healthy" with the current timestamp, doing
     * no I/O so it stays cheap for uptime probes.
     */
    #[ApiOperation(
        summary: 'Status (Lightweight)',
        description: 'Lightweight status check for the application skeleton.',
        tags: ['Status'],
    )]
    #[ApiResponse(200, StatusData::class, description: 'Service status')]
    public function status(Request $request): StatusData
    {
        return new StatusData(
            status: 'healthy',
            timestamp: date('c'),
        );
    }
}
