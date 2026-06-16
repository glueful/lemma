<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use Glueful\Database\Connection;

/**
 * Write-time projection of reference/asset fields into entry_references (V1_DESIGN §4).
 *
 * Rebuilt on every draft save inside the draft-save transaction: the source entry's
 * existing rows are deleted then re-inserted from the draft's reference field values
 * and asset blob references, deduped on the unique
 * (source_entry_uuid, source_field, target_entry_uuid).
 */
final class ReferenceProjectionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $fields the cleaned draft fields */
    public function rebuildForEntry(string $sourceEntryUuid, ContentTypeSchema $schema, array $fields): void
    {
        $this->clearForEntry($sourceEntryUuid);

        $rows = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'reference' && $f->type !== 'asset') {
                continue;
            }
            foreach (self::targets($fields[$f->name] ?? null) as $target) {
                $rows[] = [
                    'source_entry_uuid' => $sourceEntryUuid,
                    'source_field' => $f->name,
                    'target_entry_uuid' => $target,
                ];
            }
        }

        foreach ($this->dedupe($rows) as $r) {
            $this->db->table('entry_references')->insert($r);
        }
    }

    public function clearForEntry(string $sourceEntryUuid): void
    {
        $this->db->table('entry_references')
            ->where('source_entry_uuid', '=', $sourceEntryUuid)
            ->delete();
    }

    /**
     * Reverse lookup: entry uuids that reference the target ("what links here").
     *
     * @return list<string>
     */
    public function referencesTo(string $targetEntryUuid): array
    {
        return array_values(array_unique(array_column(
            $this->db->table('entry_references')
                ->select(['source_entry_uuid'])
                ->where('target_entry_uuid', '=', $targetEntryUuid)
                ->get(),
            'source_entry_uuid'
        )));
    }

    /**
     * Projection parser for uuid strings or lists of uuid strings. V1 write validation
     * currently admits single-value reference/asset fields; this parser is intentionally
     * tolerant so projection, import/export, and future multi-value fields share one
     * target-normalization path.
     *
     * @return list<string>
     */
    public static function targets(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn($v): string => is_string($v) ? $v : '', $value),
                static fn(string $v): bool => $v !== ''
            ));
        }
        return [];
    }

    /**
     * Dedupe on the unique (source_field, target_entry_uuid) within one source entry.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function dedupe(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $k = $r['source_field'] . '|' . $r['target_entry_uuid'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $r;
            }
        }
        return $out;
    }
}
