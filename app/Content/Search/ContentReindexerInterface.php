<?php

declare(strict_types=1);

namespace App\Content\Search;

use Glueful\Lemma\Contracts\Search\ContentReindexer;

/**
 * @deprecated Use Glueful\Lemma\Contracts\Search\ContentReindexer. Retained as an
 *             alias so existing bindings/implementors keep resolving during migration.
 */
interface ContentReindexerInterface extends ContentReindexer
{
}
