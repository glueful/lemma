<?php

declare(strict_types=1);

namespace App\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of one health check ({@see \App\Http\Controllers\HealthAdminController}).
 * `status` is ok | warning | error.
 */
final class HealthCheckData implements ResponseData
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
    ) {
    }
}
