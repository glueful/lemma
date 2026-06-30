<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Schema;

/**
 * Maps a CollectionField to a physical ColumnSpec (spec §4.3).
 *
 * Mapping table:
 *   collections.string      → string([length=255])
 *   collections.text  → text
 *   collections.integer   → bigInteger if bigint else integer
 *   collections.decimal   → decimal([precision, scale])
 *   collections.boolean   → boolean
 *   collections.date      → date
 *   collections.datetime  → timestamp
 *   collections.json      → text  (JSON stored as text for portability v1)
 *   collections.email     → string([255])
 *   collections.url       → string([2048])
 *   collections.enum      → string([255])
 *   collections.relation  → string([36]) single | text multi (JSON UUID array)
 *   collections.asset     → string([36]) single | text multi (JSON UUID array)
 */
final class ColumnMapper
{
    /** @var list<string> */
    private const SUPPORTED = [
        'collections.string',
        'collections.text',
        'collections.integer',
        'collections.decimal',
        'collections.boolean',
        'collections.date',
        'collections.datetime',
        'collections.json',
        'collections.email',
        'collections.url',
        'collections.enum',
        'collections.relation',
        'collections.asset',
    ];

    public function column(CollectionField $field): ColumnSpec
    {
        $s = $field->settings;
        $nullable = isset($s['nullable']) ? (bool) $s['nullable'] : true;
        $unique   = isset($s['unique'])   ? (bool) $s['unique']   : false;
        $name     = $field->name;

        switch ($field->type) {
            case 'collections.string':
                $length = isset($s['length']) ? (int) $s['length'] : 255;
                return new ColumnSpec($name, 'string', [$length], $nullable, $unique);

            case 'collections.text':
                return new ColumnSpec($name, 'text', [], $nullable, $unique);

            case 'collections.integer':
                $type = !empty($s['bigint']) ? 'bigInteger' : 'integer';
                return new ColumnSpec($name, $type, [], $nullable, $unique);

            case 'collections.decimal':
                $precision = isset($s['precision']) ? (int) $s['precision'] : 10;
                $scale     = isset($s['scale'])     ? (int) $s['scale']     : 2;
                return new ColumnSpec($name, 'decimal', [$precision, $scale], $nullable, $unique);

            case 'collections.boolean':
                return new ColumnSpec($name, 'boolean', [], $nullable, $unique);

            case 'collections.date':
                return new ColumnSpec($name, 'date', [], $nullable, $unique);

            case 'collections.datetime':
                return new ColumnSpec($name, 'timestamp', [], $nullable, $unique);

            case 'collections.json':
                return new ColumnSpec($name, 'text', [], $nullable, $unique);

            case 'collections.email':
                return new ColumnSpec($name, 'string', [255], $nullable, $unique);

            case 'collections.url':
                return new ColumnSpec($name, 'string', [2048], $nullable, $unique);

            case 'collections.enum':
                return new ColumnSpec($name, 'string', [255], $nullable, $unique);

            case 'collections.relation':
            case 'collections.asset':
                if (!empty($s['multi'])) {
                    return new ColumnSpec($name, 'text', [], $nullable, $unique);
                }
                return new ColumnSpec($name, 'string', [36], $nullable, $unique);

            default:
                throw new \InvalidArgumentException(sprintf(
                    "Unsupported field type '%s'. Supported types: %s",
                    $field->type,
                    implode(', ', self::SUPPORTED),
                ));
        }
    }

    /** @return list<string> */
    public function supportedTypes(): array
    {
        return self::SUPPORTED;
    }
}
