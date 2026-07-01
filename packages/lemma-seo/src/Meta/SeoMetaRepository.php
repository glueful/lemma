<?php

declare(strict_types=1);

namespace Glueful\Lemma\Seo\Meta;

use Glueful\Database\Connection;

/**
 * Reads/writes the seo_meta override table, keyed by (entry_uuid, locale).
 */
final class SeoMetaRepository
{
    private const COLUMNS = [
        'title', 'description', 'og_title', 'og_description', 'og_image', 'twitter_card', 'robots',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(string $entryUuid, string $locale): ?array
    {
        $row = $this->db->table('seo_meta')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->first();
        return $row === null ? null : (array) $row;
    }

    /** @param array<string,mixed> $data */
    public function upsert(string $entryUuid, string $locale, array $data): void
    {
        $payload = [];
        foreach (self::COLUMNS as $col) {
            if (array_key_exists($col, $data)) {
                $payload[$col] = $data[$col];
            }
        }
        $now = date('Y-m-d H:i:s');
        $existing = $this->find($entryUuid, $locale);

        if ($existing !== null) {
            $payload['updated_at'] = $now;
            $this->db->table('seo_meta')
                ->where('entry_uuid', '=', $entryUuid)
                ->where('locale', '=', $locale)
                ->update($payload);
            return;
        }

        $payload['entry_uuid'] = $entryUuid;
        $payload['locale'] = $locale;
        $payload['robots'] = $payload['robots'] ?? 'index';
        $payload['created_at'] = $now;
        $payload['updated_at'] = $now;
        $this->db->table('seo_meta')->insert($payload);
    }
}
