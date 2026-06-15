<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;

final class ContentTypeResultData implements ResponseData
{
    public function __construct(
        public readonly ContentTypeData $content_type,
    ) {
    }
}
