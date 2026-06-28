<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Authoring;

/**
 * High-level content authoring for packs (e.g. importers). Packs ask core to create
 * drafts and publish; they never touch content repositories or tables directly.
 */
interface ContentWriter
{
    /**
     * Create a new entry with an initial draft for $locale.
     *
     * @param array<string,mixed> $fields
     * @return string The new entry uuid.
     */
    public function createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string;

    /**
     * Publish the current draft for $entryUuid/$locale.
     *
     * @return string The publication (version) uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor = null): string;
}
