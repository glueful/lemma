<?php

declare(strict_types=1);

namespace App\Content\Validation;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

final class FieldValidator
{
    public function __construct(
        private readonly ?Connection $db = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    /**
     * Validate a fields payload against a content type schema.
     * Returns the cleaned payload (known fields only, in schema order).
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws ValidationException
     */
    public function validate(ContentTypeSchema $schema, array $payload): array
    {
        $errors = [];
        $clean = [];

        foreach ($schema->fields() as $field) {
            $present = array_key_exists($field->name, $payload);
            $value = $present ? $payload[$field->name] : null;

            if (!$present || $value === null) {
                if ($field->required) {
                    $errors[$field->name] = 'is required';
                }
                continue;
            }

            $error = $this->checkType($field, $value);
            if ($error !== null) {
                $errors[$field->name] = $error;
                continue;
            }
            if ($field->type === 'asset' && is_string($value) && !$this->assetExistsOnMediaDisk($value)) {
                $errors[$field->name] = 'must reference an active blob on the configured media disk';
                continue;
            }
            // Normalize datetime values to canonical ISO-8601 UTC so stored values are
            // lexicographically comparable as TEXT (the only IMMUTABLE index expression
            // for datetime — see FilterIndexPlanner / FilterCompiler).
            if ($field->type === 'datetime' && is_string($value)) {
                $value = self::normalizeDatetime($value);
            }
            $clean[$field->name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /**
     * Canonicalize a datetime string to ISO-8601 UTC (`YYYY-MM-DDTHH:MM:SSZ`).
     *
     * Returned form is lexicographically sortable and identical regardless of the
     * input's timezone/offset, so stored values and filter bindings compare correctly
     * as text. Callers must validate parseability first (`strtotime() !== false`).
     */
    public static function normalizeDatetime(string $value): string
    {
        $ts = strtotime($value);
        if ($ts === false) {
            return $value;
        }
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }

    private function checkType(FieldDefinition $field, mixed $value): ?string
    {
        return match ($field->type) {
            'string', 'text' => is_string($value) ? null : 'must be a string',
            'number' => (is_int($value) || is_float($value)) ? null : 'must be a number',
            'boolean' => is_bool($value) ? null : 'must be a boolean',
            'datetime' => (is_string($value) && strtotime($value) !== false) ? null : 'must be an ISO datetime',
            'enum' => in_array($value, $field->enumValues, true) ? null
                : 'must be one of: ' . implode(', ', $field->enumValues),
            'reference', 'asset' => (is_string($value) && $value !== '') ? null : 'must be a uuid',
            'json' => (is_array($value)) ? null : 'must be an object/array',
            default => 'unknown field type',
        };
    }

    private function assetExistsOnMediaDisk(string $uuid): bool
    {
        if ($this->db === null || $this->context === null) {
            return true;
        }

        $disk = (string) config($this->context, 'lemma.media_disk', 'local');
        try {
            return $this->db->table('blobs')
                ->where('uuid', '=', $uuid)
                ->where('storage_type', '=', $disk)
                ->where('status', '=', 'active')
                ->first() !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
