<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Search\Engine\SearchBackend;

/**
 * Reindexes a published entry/locale via the delivery-backed reader and the search backend.
 * locale === null (whole-entry delete) purges every locale doc; otherwise re-reads and
 * upserts, or deletes this locale's doc if the entry is no longer published/visible.
 */
final class SearchContentReindexer implements ContentReindexer
{
    public function __construct(
        private readonly IndexableContentReader $reader,
        private readonly DocumentBuilder $builder,
        private readonly SearchBackend $backend,
        private readonly ContentTypeReader $types,
    ) {
    }

    public function reindexEntry(string $entryUuid, ?string $locale): void
    {
        if ($locale === null) {
            $this->backend->deleteEntry($entryUuid, null);
            return;
        }

        $record = $this->reader->getIndexablePublished($entryUuid, $locale);
        if ($record === null) {
            $this->backend->deleteEntry($entryUuid, $locale);
            return;
        }

        $schema = $this->types->schemaFor($record->contentTypeUuid);
        if ($schema === null) {
            $this->backend->deleteEntry($entryUuid, $locale);
            return;
        }

        $this->backend->upsert([$this->builder->build($record, $schema)]);
    }
}
