<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Entries;

use App\Content\Enums\EntryStatus;
use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the raw `entries` row that
 * {@see \App\Content\Repositories\EntryRepository::findEntry()} returns as `->first()`
 * from the database. NEVER constructed at runtime — it exists only so the OpenAPI
 * generator can reflect a typed schema for the `entry` key inside the success envelope.
 * `created_at` / `updated_at` are typed as `\DateTimeInterface` / `?\DateTimeInterface`
 * to drive `format: date-time` in the generated schema; the wire value is an
 * ISO-8601-ish string but the DTO is never instantiated.
 */
final class EntryData implements ResponseData
{
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $content_type_uuid,
        public readonly EntryStatus $status,
        public readonly ?string $created_by,
        public readonly \DateTimeInterface $created_at,
        public readonly ?\DateTimeInterface $updated_at,
    ) {
    }
}
