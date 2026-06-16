<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Indexing\FieldSqlExpression;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use App\Content\Validation\FieldValidator;

/**
 * Compiles `?filter[field][op]=value` request params into a safe, typed JSONB
 * predicate over `entry_versions.fields`.
 *
 * Hard rules (V1_DESIGN §1):
 *  - Only fields the schema marks `filterable` (with a `filter_type`) are accepted;
 *    everything else throws {@see UnfilterableFieldException}.
 *  - The `filter_type` fixes both the SQL cast (via {@see FieldSqlExpression}, the same
 *    source the expression index uses, so the predicate hits the index) and the allowed
 *    operators. A disallowed/unknown operator or an uncoercible value throws
 *    {@see InvalidFilterException}.
 *  - Values are ALWAYS bound `?` placeholders, never interpolated. The field name comes
 *    from the validated schema, but is re-asserted `[a-z][a-z0-9_]*` before use.
 */
final class FilterCompiler
{
    /** Operators valid for each filter family. */
    private const ORDER_OPS = ['gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
    private const EQUALITY_OPS = ['eq' => '=', 'neq' => '<>', 'in' => 'IN'];

    /**
     * @param array<string,mixed> $filterParam  e.g. ['price' => ['gt' => '10'], 'status' => ['in' => 'a,b']]
     * @return array{sql:string,bindings:list<mixed>}
     */
    public function compile(ContentTypeSchema $schema, array $filterParam): array
    {
        $clauses = [];
        $bindings = [];

        foreach ($filterParam as $fieldName => $ops) {
            $field = $this->resolveFilterableField($schema, (string) $fieldName);

            if (!is_array($ops)) {
                throw new InvalidFilterException("filter '{$field->name}' must be an object of operators");
            }

            foreach ($ops as $op => $rawValue) {
                [$clause, $opBindings] = $this->compilePredicate($field, (string) $op, $rawValue);
                $clauses[] = $clause;
                foreach ($opBindings as $b) {
                    $bindings[] = $b;
                }
            }
        }

        return [
            'sql' => implode(' AND ', $clauses),
            'bindings' => $bindings,
        ];
    }

    private function resolveFilterableField(ContentTypeSchema $schema, string $name): FieldDefinition
    {
        $field = $schema->field($name);
        if ($field === null || !$field->filterable || $field->filterType === null) {
            throw new UnfilterableFieldException("field '{$name}' is not filterable");
        }
        // Defense in depth: re-assert the name shape before interpolating it into SQL.
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $field->name) !== 1) {
            throw new UnfilterableFieldException("unsafe field name: '{$field->name}'");
        }
        return $field;
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function compilePredicate(FieldDefinition $field, string $op, mixed $rawValue): array
    {
        $filterType = (string) $field->filterType;
        $expr = FieldSqlExpression::cast($field->name, $filterType);
        $isOrdered = in_array($filterType, ['number', 'datetime'], true);

        // Ordered types (number/datetime): gt/gte/lt/lte. Others: eq/neq/in.
        if ($isOrdered) {
            if (!isset(self::ORDER_OPS[$op])) {
                throw new InvalidFilterException(
                    "operator '{$op}' is not allowed for {$filterType} field '{$field->name}'"
                );
            }
            $value = $this->coerce($field, $filterType, $rawValue);
            return ["{$expr} " . self::ORDER_OPS[$op] . ' ?', [$value]];
        }

        if (!isset(self::EQUALITY_OPS[$op])) {
            throw new InvalidFilterException(
                "operator '{$op}' is not allowed for {$filterType} field '{$field->name}'"
            );
        }

        if ($op === 'in') {
            $values = $this->coerceList($field, $filterType, $rawValue);
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            return ["{$expr} IN ({$placeholders})", $values];
        }

        $value = $this->coerce($field, $filterType, $rawValue);
        return ["{$expr} " . self::EQUALITY_OPS[$op] . ' ?', [$value]];
    }

    /** Coerce + validate a single scalar value for the filter type. */
    private function coerce(FieldDefinition $field, string $filterType, mixed $raw): mixed
    {
        if (is_array($raw)) {
            throw new InvalidFilterException("filter value for '{$field->name}' must be a scalar");
        }
        $value = (string) $raw;

        return match ($filterType) {
            'number' => $this->coerceNumber($field, $value),
            'boolean' => $this->coerceBoolean($field, $value),
            'datetime' => $this->coerceDatetime($field, $value),
            // string | enum: bound as text.
            default => $value,
        };
    }

    /**
     * Split a comma-separated `in` list, coercing each element.
     * @return list<mixed>
     */
    private function coerceList(FieldDefinition $field, string $filterType, mixed $raw): array
    {
        if (is_array($raw)) {
            $parts = array_values($raw);
        } else {
            $parts = explode(',', (string) $raw);
        }
        $parts = array_values(array_filter(
            array_map(static fn($v): string => is_string($v) ? trim($v) : (string) $v, $parts),
            static fn(string $v): bool => $v !== ''
        ));
        if ($parts === []) {
            throw new InvalidFilterException("filter 'in' for '{$field->name}' requires at least one value");
        }
        return array_map(fn(string $v): mixed => $this->coerce($field, $filterType, $v), $parts);
    }

    private function coerceNumber(FieldDefinition $field, string $value): int|float
    {
        if (!is_numeric($value)) {
            throw new InvalidFilterException("filter value for '{$field->name}' must be numeric");
        }
        $num = $value + 0;
        return $num;
    }

    private function coerceBoolean(FieldDefinition $field, string $value): bool
    {
        $lower = strtolower($value);
        if (in_array($lower, ['true', '1'], true)) {
            return true;
        }
        if (in_array($lower, ['false', '0'], true)) {
            return false;
        }
        throw new InvalidFilterException("filter value for '{$field->name}' must be a boolean");
    }

    private function coerceDatetime(FieldDefinition $field, string $value): string
    {
        if (strtotime($value) === false) {
            throw new InvalidFilterException("filter value for '{$field->name}' must be an ISO datetime");
        }
        // Normalize to the SAME canonical ISO-8601 UTC form the value is stored as, so
        // the TEXT comparison against the stored value is correct.
        return FieldValidator::normalizeDatetime($value);
    }
}
