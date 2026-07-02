<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Seo\PathRenderer;
use Glueful\Database\Connection;
use Glueful\Lemma\Contracts\Delivery\EntryTargetResolver;

/** Engine-backed EntryTargetResolver over entries/publications/routes/content_types. */
final class EngineEntryTargetResolver implements EntryTargetResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly PathRenderer $paths,
    ) {
    }

    public function resolve(string $entryUuid, string $locale): array
    {
        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)->first();
        if ($entry === null) {
            return ['status' => 'missing', 'path' => null];
        }
        if (($entry['status'] ?? null) === 'deleted') {
            return ['status' => 'deleted', 'path' => null];
        }

        $publication = $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($publication === null) {
            return ['status' => 'unpublished', 'path' => null];
        }
        $route = $this->db->table('entry_routes')->select(['slug'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        // Published-but-routeless: live content that cannot be linked until a route is
        // assigned. Distinct status so the menu editor can say "assign a route" rather
        // than "publish this"; path stays null so no consumer renders a dead link.
        if ($route === null) {
            return ['status' => 'routeless', 'path' => null];
        }

        $type = $this->db->table('content_types')->select(['slug'])
            ->where('uuid', '=', (string) $entry['content_type_uuid'])->first();
        $path = $this->paths->render((string) ($type['slug'] ?? ''), $locale, (string) $route['slug']);
        return ['status' => 'published', 'path' => $path];
    }
}
