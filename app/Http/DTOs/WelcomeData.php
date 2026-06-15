<?php

declare(strict_types=1);

namespace App\Http\DTOs;

use Glueful\Http\Contracts\ResponseData;

/**
 * Welcome payload returned by GET /welcome.
 *
 * Static-shape envelope: keys are fixed, only the values are computed at runtime.
 * Enveloped at HTTP 200 with the default `Success` message — byte-identical to the
 * previous `Response::success([...])` call.
 */
final class WelcomeData implements ResponseData
{
    public function __construct(
        public readonly string $message,
        public readonly string $version,
        public readonly string $timestamp,
    ) {
    }
}
