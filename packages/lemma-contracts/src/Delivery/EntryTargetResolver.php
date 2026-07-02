<?php

declare(strict_types=1);

namespace Glueful\Lemma\Contracts\Delivery;

/**
 * Published-semantics target check for a single entry+locale — the read navigation
 * needs per menu item and render's path() helper reuses. Statuses:
 *   published   — pinned publication AND a public route (addressable); path resolved
 *   routeless   — pinned publication but NO route: live content that cannot be linked
 *                 until a route is assigned (actionable editor state)
 *   unpublished — entry exists (draft) but has no publication in this locale
 *   deleted     — soft-deleted entry
 *   missing     — no such entry
 * path is null for EVERY non-published status — no consumer can produce a dead link.
 */
interface EntryTargetResolver
{
    /**
     * @return array{status: 'published'|'unpublished'|'deleted'|'missing'|'routeless',
     *   path: ?string}  path is non-null iff status is 'published'
     */
    public function resolve(string $entryUuid, string $locale): array;
}
