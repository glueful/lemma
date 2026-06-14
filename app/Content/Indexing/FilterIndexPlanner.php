<?php

declare(strict_types=1);

namespace App\Content\Indexing;

use App\Content\Schema\ContentTypeSchema;

/**
 * Derives the desired Postgres expression indexes for a content type's filterable fields.
 *
 * The index expression's cast is fixed by the field's declared `filter_type` (V1_DESIGN §1)
 * and MUST match the cast the FilterCompiler (Task 4) generates, so the planner is the single
 * source of truth for both the index and the query predicate's typing.
 */
final class FilterIndexPlanner
{
    /**
     * Build the set of expression indexes a content type's current schema wants.
     *
     * @return list<array{field:string,filter_type:string,index_name:string,expression:string}>
     */
    public function desiredIndexes(ContentTypeSchema $schema, string $typeUuid): array
    {
        $out = [];
        foreach ($schema->fields() as $field) {
            if (!$field->filterable || $field->filterType === null) {
                continue;
            }
            $name = $field->name;
            // Defense in depth: the field name is already validated [a-z][a-z0-9_]* by
            // FieldDefinition, but we re-assert it before interpolating into raw DDL.
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new \InvalidArgumentException("unsafe field name for index expression: '{$name}'");
            }
            $out[] = [
                'field' => $name,
                'filter_type' => $field->filterType,
                'index_name' => 'lemma_fidx_' . substr(sha1($typeUuid . $name), 0, 16),
                'expression' => $this->expression($name, $field->filterType),
            ];
        }
        return $out;
    }

    /**
     * The JSONB expression (with the type cast) used for both the index and the filter predicate.
     */
    private function expression(string $field, string $filterType): string
    {
        return match ($filterType) {
            'number' => "((fields ->> '{$field}')::numeric)",
            // datetime is indexed/compared as TEXT (the only IMMUTABLE option — a
            // ::timestamptz/::timestamp cast is not IMMUTABLE because text->timestamp
            // parsing is DateStyle-dependent, so CREATE INDEX rejects it). Text comparison
            // sorts datetimes chronologically ONLY if values are stored as canonical,
            // lexicographically-sortable ISO-8601 (e.g. UTC `YYYY-MM-DDTHH:MM:SSZ`). The
            // FilterCompiler (Task 4) must compare datetime as text, and the FieldValidator
            // must normalize datetime values on write — tracked for Task 4.
            'datetime' => "((fields ->> '{$field}'))",
            'boolean' => "((fields ->> '{$field}')::boolean)",
            default => "((fields ->> '{$field}'))", // string | enum (text)
        };
    }
}
