<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Data;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Lemma\Collections\Exceptions\RowValidationException;
use Glueful\Lemma\Collections\Schema\CollectionDefinition;
use Glueful\Lemma\Collections\Schema\CollectionField;
use Glueful\Repository\BlobRepository;

/**
 * Validates and coerces row input data against a collection's field schema.
 *
 * Returns a column map with coerced values ready for database insertion/update.
 * Throws RowValidationException carrying per-field errors on any failure.
 *
 * Validation rules per field type:
 *   collections.text/longtext/json  — cast to string, no further validation
 *   collections.integer             — "42" → 42; decimal strings are rejected
 *   collections.decimal             — kept as string; must be numeric
 *   collections.boolean             — "true"/"1"/true → true; "false"/"0"/false → false
 *   collections.date                — Y-m-d format only
 *   collections.datetime            — Y-m-d H:i:s format only
 *   collections.email               — filter_var FILTER_VALIDATE_EMAIL
 *   collections.url                 — filter_var FILTER_VALIDATE_URL
 *   collections.enum                — must be in settings['values']
 *   collections.relation            — shape only: string (single) or string[] (multi); target not checked
 *   collections.asset               — blob uuid(s) must resolve via BlobRepository::findByUuid
 *   unique constraint               — queried against the collection's table; current row excluded on update
 */
final class RowValidator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BlobRepository $blobRepository,
    ) {
    }

    /**
     * Validate and coerce input data against the collection's schema.
     *
     * @param array<string, mixed> $input       Key-value field data from the caller.
     * @param bool                 $partial      When true, absent fields are skipped (partial update).
     * @param string|null          $currentUuid  UUID of the row being updated; used to exclude the
     *                                           current row from unique-constraint checks.
     * @return array<string, mixed> Coerced column map, containing only fields present in $input
     *                              (plus explicit null values for nullable absent fields on full writes).
     * @throws RowValidationException on any per-field validation failure.
     */
    public function validate(
        CollectionDefinition $def,
        array $input,
        bool $partial,
        ?string $currentUuid = null,
    ): array {
        $errors  = [];
        $coerced = [];

        foreach ($def->fields as $field) {
            $isPresent = array_key_exists($field->name, $input);

            if (!$isPresent) {
                if ($partial) {
                    continue;
                }

                $isNullable = isset($field->settings['nullable'])
                    ? (bool) $field->settings['nullable']
                    : true;

                if (!$isNullable) {
                    $errors[$field->name] = sprintf("Field '%s' is required.", $field->name);
                }

                continue;
            }

            $value = $input[$field->name];

            if ($value === null) {
                $isNullable = isset($field->settings['nullable'])
                    ? (bool) $field->settings['nullable']
                    : true;

                if (!$isNullable) {
                    $errors[$field->name] = sprintf("Field '%s' cannot be null.", $field->name);
                    continue;
                }

                $coerced[$field->name] = null;
                continue;
            }

            [$coercedValue, $error] = $this->coerce($def, $field, $value, $currentUuid);

            if ($error !== null) {
                $errors[$field->name] = $error;
            } else {
                $coerced[$field->name] = $coercedValue;
            }
        }

        if ($errors !== []) {
            throw RowValidationException::make($errors);
        }

        return $coerced;
    }

    /**
     * Coerce and validate a single non-null field value.
     *
     * @param mixed $value
     * @return array{0: mixed, 1: string|null}  [coerced value, error message or null]
     */
    private function coerce(
        CollectionDefinition $def,
        CollectionField $field,
        mixed $value,
        ?string $currentUuid,
    ): array {
        $name = $field->name;

        switch ($field->type) {
            case 'collections.text':
            case 'collections.longtext':
            case 'collections.json':
                $coerced = (string) $value;
                break;

            case 'collections.integer':
                $filtered = filter_var($value, FILTER_VALIDATE_INT);
                if ($filtered === false) {
                    return [null, sprintf("Field '%s' must be an integer.", $name)];
                }
                $coerced = $filtered;
                break;

            case 'collections.decimal':
                if (!is_numeric($value)) {
                    return [null, sprintf("Field '%s' must be a decimal number.", $name)];
                }
                $coerced = (string) $value;
                break;

            case 'collections.boolean':
                $coerced = $this->coerceBoolean($value);
                if ($coerced === null) {
                    return [null, sprintf("Field '%s' must be a boolean.", $name)];
                }
                break;

            case 'collections.date':
                $coerced = $this->coerceDate($value);
                if ($coerced === null) {
                    return [null, sprintf("Field '%s' must be a valid date (Y-m-d).", $name)];
                }
                break;

            case 'collections.datetime':
                $coerced = $this->coerceDatetime($value);
                if ($coerced === null) {
                    return [null, sprintf("Field '%s' must be a valid datetime (Y-m-d H:i:s).", $name)];
                }
                break;

            case 'collections.email':
                $coerced = (string) $value;
                if (!Utils::isValidEmail($coerced)) {
                    return [null, sprintf("Field '%s' must be a valid email address.", $name)];
                }
                break;

            case 'collections.url':
                $coerced = (string) $value;
                if (Utils::validateUrl($coerced) === null) {
                    return [null, sprintf("Field '%s' must be a valid URL.", $name)];
                }
                break;

            case 'collections.enum':
                $coerced  = (string) $value;
                $allowed  = (array) ($field->settings['values'] ?? []);
                if ($allowed !== [] && !in_array($coerced, $allowed, true)) {
                    return [null, sprintf(
                        "Field '%s' must be one of: %s.",
                        $name,
                        implode(', ', $allowed),
                    )];
                }
                break;

            case 'collections.relation':
                $isMulti = !empty($field->settings['multi']);
                if ($isMulti) {
                    if (!is_array($value)) {
                        return [null, sprintf("Field '%s' must be an array of UUIDs.", $name)];
                    }
                    foreach ($value as $i => $uuid) {
                        if (!is_string($uuid)) {
                            return [null, sprintf(
                                "Field '%s' element %d must be a string UUID.",
                                $name,
                                (int) $i,
                            )];
                        }
                    }
                    $coerced = (string) json_encode(array_values($value), JSON_THROW_ON_ERROR);
                } else {
                    if (!is_string($value)) {
                        return [null, sprintf("Field '%s' must be a string UUID.", $name)];
                    }
                    $coerced = $value;
                }
                break;

            case 'collections.asset':
                $isMulti = !empty($field->settings['multi']);
                if ($isMulti) {
                    if (!is_array($value)) {
                        return [null, sprintf("Field '%s' must be an array of blob UUIDs.", $name)];
                    }
                    foreach ($value as $i => $uuid) {
                        if (!is_string($uuid)) {
                            return [null, sprintf(
                                "Field '%s' element %d must be a string UUID.",
                                $name,
                                (int) $i,
                            )];
                        }
                        if ($this->blobRepository->findByUuid($uuid) === null) {
                            return [null, sprintf(
                                "Field '%s' element %d references a non-existent blob UUID '%s'.",
                                $name,
                                (int) $i,
                                $uuid,
                            )];
                        }
                    }
                    $coerced = (string) json_encode(array_values($value), JSON_THROW_ON_ERROR);
                } else {
                    if (!is_string($value)) {
                        return [null, sprintf("Field '%s' must be a string blob UUID.", $name)];
                    }
                    if ($this->blobRepository->findByUuid($value) === null) {
                        return [null, sprintf(
                            "Field '%s' references a non-existent blob UUID '%s'.",
                            $name,
                            $value,
                        )];
                    }
                    $coerced = $value;
                }
                break;

            default:
                // A registered field type with no coercion rule is a pack misconfiguration,
                // not user input — fail loudly rather than silently storing it as a string.
                throw new \LogicException(sprintf(
                    "RowValidator: no coercion rule for field type '%s' (field '%s').",
                    $field->type,
                    $name,
                ));
        }

        // Unique constraint check (runs only when coercion succeeded).
        if (!empty($field->settings['unique'])) {
            $query = $this->connection
                ->table($def->tableName)
                ->where($name, $coerced);

            if ($currentUuid !== null) {
                $query->where('uuid', '!=', $currentUuid);
            }

            if ($query->count() > 0) {
                return [null, sprintf(
                    "Field '%s' must be unique, but value '%s' already exists.",
                    $name,
                    (string) $coerced,
                )];
            }
        }

        return [$coerced, null];
    }

    private function coerceBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            if ($value === 1) {
                return true;
            }
            if ($value === 0) {
                return false;
            }
            return null;
        }

        if (is_string($value)) {
            if (in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array(strtolower($value), ['false', '0', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    private function coerceDate(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            return null;
        }

        // Guard against PHP silently accepting invalid dates (e.g. 2024-02-30).
        if ($date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function coerceDatetime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $date = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
        if ($date === false) {
            return null;
        }

        if ($date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }

        return $date->format('Y-m-d H:i:s');
    }
}
