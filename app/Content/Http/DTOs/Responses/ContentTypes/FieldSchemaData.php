<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use App\Content\Enums\FieldType;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class FieldSchemaData implements ResponseData
{
    /** @param list<string> $enum */
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly ?bool $required = null,
        public readonly ?bool $localized = null,
        public readonly ?bool $filterable = null,
        public readonly ?string $filter_type = null,
        #[ArrayOf('string')]
        public readonly array $enum = [],
    ) {
    }
}
