<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\ContentTypes;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class ContentTypeData implements ResponseData
{
    /** @param list<FieldSchemaData> $schema */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        #[ArrayOf(FieldSchemaData::class)]
        public readonly array $schema,
        public readonly int $schema_version,
        public readonly ?string $created_by,
        public readonly \DateTimeInterface $created_at,
        public readonly ?\DateTimeInterface $updated_at,
    ) {
    }
}
