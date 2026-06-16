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
}
