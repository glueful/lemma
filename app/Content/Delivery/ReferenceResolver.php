<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Schema\ContentTypeSchema;
use Glueful\Support\FieldSelection\FieldSelector;

/**
 * Resolves entry-UUID references in delivery rows to their target's **published**
 * version, at read time, with batch loading.
 *
 * V1_DESIGN §4: references point at *entries*; the delivery API resolves them to the
 * target's published version. An unpublished (or archived) target resolves to `null`
 * — never to a draft. Expansion uses batch loading: for each level of `$rootRows`,
 * all reference/asset target uuids are collected and resolved with **one** query
 * (per locale set) via {@see DeliveryRepository::publishedByEntryUuids()}, not a
 * per-entry fetch. Circular references (A → A) are bounded by `$depth`; at the limit,
 * the reference value is left as the raw uuid (not expanded further).
 *
 * Field selection: when a non-empty {@see FieldSelector} is provided, only reference
 * fields whose top-level path was requested are expanded. When `$selector` is `null`
 * or empty, ALL reference/asset fields are expanded up to `$depth` — the controller
 * (Task 7) passes the real selector; this keeps the resolver usable standalone.
 */
final class ReferenceResolver
{
    public function __construct(private readonly DeliveryRepository $repo)
    {
    }

    /**
     * Expand the reference/asset fields of each root row in place.
     *
     * @param list<array<string,mixed>> $rootRows hydrated delivery rows (each has a
     *        decoded `fields` array)
     * @param ContentTypeSchema $schema the schema for the rows in $rootRows (used to
     *        know which fields are reference/asset)
     * @param FieldSelector|null $selector scopes which reference fields to expand;
     *        null/empty => expand all reference fields
     * @param string $locale resolve targets in this locale
     * @param int $depth remaining expansion depth (default 2); 0 stops recursion
     * @return list<array<string,mixed>> the rows with references spliced in
     */
    public function expand(
        array $rootRows,
        ContentTypeSchema $schema,
        ?FieldSelector $selector,
        string $locale,
        int $depth = 2,
    ): array {
        if ($rootRows === [] || $depth <= 0) {
            return $rootRows;
        }

        $referenceFields = $this->referenceFieldNames($schema, $selector);
        if ($referenceFields === []) {
            return $rootRows;
        }

        // 1) Collect every target uuid across all rows for the reference fields (one set).
        $targetUuids = $this->collectTargets($rootRows, $referenceFields);
        if ($targetUuids === []) {
            return $rootRows;
        }

        // 2) Batch-resolve the published versions in ONE query.
        $resolved = $this->repo->publishedByEntryUuids($targetUuids, $locale);

        // 3) Recurse: expand references inside the resolved targets (same type/schema in
        //    the self-referential case; for cross-type we still use the source schema's
        //    reference field names, which is correct for the homogeneous v1 model). The
        //    targets share the schema only when they are the same type; to keep it safe
        //    and bounded we recurse with the same schema. Depth bounds the recursion.
        if ($depth - 1 > 0 && $resolved !== []) {
            $resolved = $this->indexExpanded(
                $this->expand(array_values($resolved), $schema, $selector, $locale, $depth - 1),
                array_keys($resolved)
            );
        }

        // 4) Splice resolved published `fields` back in place of each uuid.
        foreach ($rootRows as $i => $row) {
            /** @var array<string,mixed> $fields */
            $fields = $row['fields'] ?? [];
            foreach ($referenceFields as $field) {
                if (!array_key_exists($field, $fields)) {
                    continue;
                }
                $fields[$field] = $this->splice($fields[$field], $resolved);
            }
            $rootRows[$i]['fields'] = $fields;
        }

        return $rootRows;
    }

    /**
     * Re-key the recursively-expanded rows back to their entry uuids so {@see splice()}
     * can look them up. The expand() call preserves order, so we zip the expanded list
     * back onto the original key order.
     *
     * @param list<array<string,mixed>> $expandedRows
     * @param list<string> $keys
     * @return array<string,array<string,mixed>>
     */
    private function indexExpanded(array $expandedRows, array $keys): array
    {
        $out = [];
        foreach ($expandedRows as $i => $row) {
            $out[$keys[$i]] = $row;
        }
        return $out;
    }

    /**
     * Replace a reference field value (a uuid string, or a list of uuid strings) with
     * the resolved row(s). A scalar uuid → the resolved row or `null`. A list → each
     * element resolved independently (unpublished elements become `null`).
     *
     * @param array<string,array<string,mixed>> $resolved
     */
    private function splice(mixed $value, array $resolved): mixed
    {
        if (is_string($value)) {
            return $resolved[$value] ?? null;
        }
        if (is_array($value)) {
            return array_map(
                static fn(mixed $v): mixed => is_string($v) ? ($resolved[$v] ?? null) : $v,
                array_values($value)
            );
        }
        return $value;
    }

    /**
     * The reference/asset field names to expand, honouring the selector.
     *
     * @return list<string>
     */
    private function referenceFieldNames(ContentTypeSchema $schema, ?FieldSelector $selector): array
    {
        $scoped = $selector !== null && !$selector->empty();
        $names = [];
        foreach ($schema->fields() as $field) {
            if ($field->type !== 'reference' && $field->type !== 'asset') {
                continue;
            }
            if ($scoped && !$selector->requested($field->name)) {
                continue;
            }
            $names[] = $field->name;
        }
        return $names;
    }

    /**
     * Collect the distinct target uuids referenced across all rows for the given fields.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $fields
     * @return list<string>
     */
    private function collectTargets(array $rows, array $fields): array
    {
        $uuids = [];
        foreach ($rows as $row) {
            /** @var array<string,mixed> $rowFields */
            $rowFields = $row['fields'] ?? [];
            foreach ($fields as $field) {
                foreach ($this->uuidsIn($rowFields[$field] ?? null) as $uuid) {
                    $uuids[$uuid] = true;
                }
            }
        }
        return array_keys($uuids);
    }

    /**
     * A reference value is a uuid string or a list of uuid strings.
     *
     * @return list<string>
     */
    private function uuidsIn(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn(mixed $v): string => is_string($v) ? $v : '', $value),
                static fn(string $v): bool => $v !== ''
            ));
        }
        return [];
    }
}
