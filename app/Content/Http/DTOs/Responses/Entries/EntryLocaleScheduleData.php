<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryLocaleScheduleData implements ResponseData
{
    public function __construct(
        public readonly ?string $publish,
        public readonly ?string $unpublish,
        public readonly ?EntryLocaleScheduleFailureData $last_failure,
    ) {
    }
}
