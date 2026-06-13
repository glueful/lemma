<?php

declare(strict_types=1);

namespace App\Content\Validation;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Schema\FieldDefinition;

final class FieldValidator
{
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
            $clean[$field->name] = $value;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
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
}
