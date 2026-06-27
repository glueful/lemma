<?php

declare(strict_types=1);

namespace App\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only envelope for the General settings show/update responses
 * ({@see \App\Http\Controllers\GeneralSettingsController}).
 */
final class GeneralSettingsResultData implements ResponseData
{
    public function __construct(
        public readonly GeneralSettingsData $settings,
    ) {
    }
}
