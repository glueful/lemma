<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\DTOs\StatusData;
use App\Http\DTOs\WelcomeData;
use Glueful\Controllers\BaseController;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

class WelcomeController extends BaseController
{
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
