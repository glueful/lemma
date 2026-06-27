<?php

declare(strict_types=1);

namespace App\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the health response
 * ({@see \App\Http\Controllers\HealthAdminController::show()}).
 */
final class HealthResultData implements ResponseData
{
    public function __construct(
        public readonly HealthData $health,
    ) {
    }
}
