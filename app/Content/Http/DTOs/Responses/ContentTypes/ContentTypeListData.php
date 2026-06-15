<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class ContentTypeListData implements ResponseData
{
    /** @param list<ContentTypeData> $content_types */
    public function __construct(
        #[ArrayOf(ContentTypeData::class)]
        public readonly array $content_types,
    ) {
    }
}
