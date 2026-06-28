<?php

declare(strict_types=1);

namespace App\Content\Indexing;

/**
 * The single source of truth for the per-filter-type JSONB SQL expression.
 *
 * Both the expression index (FilterIndexPlanner / EnsureFilterIndexesJob) and the
 * query predicate (FilterCompiler) build their cast from here, so the predicate is
 * guaranteed to hit the index — they cannot drift.
 *
 * `datetime` is intentionally TEXT: a `::timestamptz`/`::timestamp` cast is not
 * IMMUTABLE (DateStyle-dependent parsing) so Postgres rejects it in an index
 * expression. Text comparison sorts datetimes chronologically only when values are
 * stored as canonical ISO-8601 UTC (see FieldValidator::normalizeDatetime).
 */
final class FieldSqlExpression
{
    /**
     * The typed cast expression for a filter type, e.g. `(fields ->> 'price')::numeric`.
     *
     * The caller MUST ensure `$field` is a validated `[a-z][a-z0-9_]*` name before
     * interpolating it (it is interpolated, never bound).
     */
    public static function cast(string $field, string $filterType): string
    {
        return match ($filterType) {
            'number' => "(fields ->> '{$field}')::numeric",
            'boolean' => "(fields ->> '{$field}')::boolean",
            // string | enum | datetime -> text
            default => "(fields ->> '{$field}')",
        };
    }

    /**
     * Normalize `fields->'F'` to a jsonb array regardless of stored shape, so single, multi, and
     * flipped-across-versions reference/asset values all filter identically via `@>`. IMMUTABLE
     * (jsonb_typeof/jsonb_build_array are immutable), so it is usable in a GIN expression index.
     *
     * The caller MUST pass a validated `[a-z][a-z0-9_]*` field name — it is interpolated, never bound.
     */
    public static function membershipArray(string $field): string
    {
        return 'CASE'
            . " WHEN fields -> '{$field}' IS NULL THEN '[]'::jsonb"
            . " WHEN jsonb_typeof(fields -> '{$field}') = 'array' THEN fields -> '{$field}'"
            . " ELSE jsonb_build_array(fields -> '{$field}') END";
    }
}
