<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use Glueful\Database\Connection;

final class RouteRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Upsert the route for an entry+locale (one slug per entry+locale). */
    public function assign(string $entryUuid, string $contentTypeUuid, string $locale, string $slug): void
    {
        $existing = $this->db->table('entry_routes')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($existing === null) {
            $this->db->table('entry_routes')->insert([
                'entry_uuid' => $entryUuid,
                'content_type_uuid' => $contentTypeUuid,
                'locale' => $locale,
                'slug' => $slug,
            ]);
        } else {
            $this->db->table('entry_routes')
                ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
                ->update(['slug' => $slug, 'content_type_uuid' => $contentTypeUuid]);
        }
    }

    public function isSlugAvailable(string $contentTypeUuid, string $locale, string $slug): bool
    {
        return $this->db->table('entry_routes')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('slug', '=', $slug)
            ->first() === null;
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $contentTypeUuid, string $locale, string $slug): ?array
    {
        return $this->db->table('entry_routes')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('slug', '=', $slug)
            ->first() ?: null;
    }

    /** @return list<array<string,mixed>> */
    public function forEntry(string $entryUuid): array
    {
        return $this->db->table('entry_routes')
            ->where('entry_uuid', '=', $entryUuid)
            ->orderBy('locale', 'ASC')
            ->get();
    }

    public function remove(string $entryUuid, string $locale): void
    {
        $this->db->table('entry_routes')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->delete();
    }
}
