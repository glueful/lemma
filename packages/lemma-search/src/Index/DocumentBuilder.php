<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Index;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Search\IndexableContent;

/**
 * Builds the shared-index search document for one published entry+locale.
 *
 * The `lemma_content` index has two searchable attributes, `title` (ranked first) and
 * `body`. Per-type `weights` cannot re-order index-global searchable attributes, so they
 * instead order the fields concatenated into `body` (highest weight first).
 */
final class DocumentBuilder
{
    private const INDEXABLE_TYPES = ['string', 'text'];

    /** @param array<string,array<string,mixed>> $typeConfig config('lemma_search.types') */
    public function __construct(private readonly array $typeConfig)
    {
    }

    /** @return array<string,mixed> */
    public function build(IndexableContent $content, ContentSchemaReader $schema): array
    {
        $stringFields = $this->stringFieldNames($schema);
        $cfg = $this->typeConfig[$content->contentTypeSlug] ?? [];

        $exclude = array_map('strval', (array) ($cfg['exclude_fields'] ?? []));
        $selectable = array_values(array_diff($stringFields, $exclude));

        // Title.
        $titleField = isset($cfg['title_field']) ? (string) $cfg['title_field'] : 'title';
        $title = $this->stringValue($content->fields, $titleField);
        if ($title === null && $titleField === 'title') {
            // Convention chain: entryLabel → first indexed string field.
            $title = $content->entryLabel;
            if ($title === null || $title === '') {
                foreach ($selectable as $name) {
                    $v = $this->stringValue($content->fields, $name);
                    if ($v !== null && $v !== '') {
                        $title = $v;
                        break;
                    }
                }
            }
        }

        // Body field ordering.
        if (isset($cfg['body_fields'])) {
            $bodyFields = array_values(array_filter(
                array_map('strval', (array) $cfg['body_fields']),
                fn (string $f): bool => in_array($f, $selectable, true),
            ));
        } else {
            $bodyFields = array_values(array_filter($selectable, fn (string $f): bool => $f !== $titleField));
        }
        $bodyFields = $this->orderByWeight($bodyFields, (array) ($cfg['weights'] ?? []));

        $bodyParts = [];
        foreach ($bodyFields as $name) {
            $v = $this->stringValue($content->fields, $name);
            if ($v !== null && $v !== '') {
                $bodyParts[] = $v;
            }
        }

        return [
            'id' => $content->entryUuid . ':' . $content->locale,
            'entry_uuid' => $content->entryUuid,
            'locale' => $content->locale,
            'content_type_uuid' => $content->contentTypeUuid,
            'content_type_slug' => $content->contentTypeSlug,
            'public_delivery' => $content->publicDelivery,
            'href' => $content->href,
            'title' => (string) ($title ?? ''),
            'body' => implode("\n\n", $bodyParts),
        ];
    }

    /** @return list<string> Non-fatal config warnings for `search:status`. */
    public function validate(string $typeSlug, ContentSchemaReader $schema): array
    {
        $cfg = $this->typeConfig[$typeSlug] ?? [];
        $warnings = [];

        $configured = [];
        if (isset($cfg['title_field'])) {
            $configured[] = (string) $cfg['title_field'];
        }
        foreach ((array) ($cfg['body_fields'] ?? []) as $f) {
            $configured[] = (string) $f;
        }
        foreach ((array) ($cfg['exclude_fields'] ?? []) as $f) {
            $configured[] = (string) $f;
        }

        foreach ($configured as $name) {
            $field = $schema->field($name);
            if ($field === null) {
                $warnings[] = "[{$typeSlug}] configured field '{$name}' does not exist in the schema (skipped).";
                continue;
            }
            if (!in_array($field->type(), self::INDEXABLE_TYPES, true)) {
                $warnings[] = "[{$typeSlug}] configured field '{$name}' is type '{$field->type()}', "
                    . 'not string/text (skipped).';
            }
        }

        return $warnings;
    }

    /** @return list<string> */
    private function stringFieldNames(ContentSchemaReader $schema): array
    {
        $names = [];
        foreach ($schema->fields() as $field) {
            if (in_array($field->type(), self::INDEXABLE_TYPES, true)) {
                $names[] = $field->name();
            }
        }
        return $names;
    }

    /**
     * @param list<string> $fields
     * @param array<string,mixed> $weights
     * @return list<string>
     */
    private function orderByWeight(array $fields, array $weights): array
    {
        if ($weights === []) {
            return $fields;
        }
        $keyed = array_values($fields);
        usort($keyed, function (string $a, string $b) use ($weights, $fields): int {
            $wa = (int) ($weights[$a] ?? 0);
            $wb = (int) ($weights[$b] ?? 0);
            if ($wa === $wb) {
                return array_search($a, $fields, true) <=> array_search($b, $fields, true);
            }
            return $wb <=> $wa; // higher weight first
        });
        return $keyed;
    }

    /** @param array<string,mixed> $fields */
    private function stringValue(array $fields, string $name): ?string
    {
        $v = $fields[$name] ?? null;
        return is_string($v) ? $v : null;
    }
}
