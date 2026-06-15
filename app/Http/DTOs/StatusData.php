<?php

declare(strict_types=1);

namespace App\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Lightweight status payload returned by GET /v1/status.
 *
 * Static-shape envelope: keys are fixed, only the values are computed at runtime.
 * Enveloped at HTTP 200 with the default `Success` message — byte-identical to the
 * previous `Response::success([...])` call.
 */
final class StatusData implements ResponseData
{
    public function __construct(
        public readonly string $status,
        public readonly string $timestamp,
    ) {
    }
}
