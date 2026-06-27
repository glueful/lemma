<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryLocaleScheduleFailureData implements ResponseData
{
    public function __construct(
        public readonly string $action,
        public readonly ?string $run_at,
        public readonly string $reason,
    ) {
    }
}
