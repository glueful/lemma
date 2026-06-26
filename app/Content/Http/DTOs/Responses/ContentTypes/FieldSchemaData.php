<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use App\Content\Enums\FieldType;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: mirrors one field-schema entry inside the `schema` array the
 * runtime emits on every `content_type` object (see {@see ContentTypeData}).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for each field definition.
 *
 * Field types are chosen to drive the generated schema, not to match the PHP wire type:
 * `type` is a {@see \App\Content\Enums\FieldType} enum so the reflector emits an
 * `enum:` constraint in the generated spec (the wire value is the backing string).
 * Most optional fields (`required`, `localized`, `filterable`, `filter_type`, `enum`) are
 * absent when falsy — `ContentTypeSchema::toArray()` omits them — so callers should treat
 * all nullable/optional properties as absent-if-falsy rather than always present.
 */
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
        /** Presentation widget for text fields: 'plain' (textarea) or 'rich' (editor). */
        public readonly ?string $format = null,
    ) {
    }
}
