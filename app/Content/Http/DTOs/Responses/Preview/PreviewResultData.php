<?php

declare(strict_types=1);

namespace App\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by
 * {@see \App\Content\Http\Controllers\PreviewController::show()} (HTTP 200).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect
 * a typed schema for the `{preview}` wrapper returned when reading a previewed draft
 * or pinned version via a signed preview token.
 */
final class PreviewResultData implements ResponseData
{
    public function __construct(
        public readonly PreviewData $preview,
    ) {
    }
}
