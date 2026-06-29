<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Exceptions;

/**
 * Thrown by DdlPlanner when a schema change cannot be expressed as a safe operation.
 *
 * The only v1 blocked operation is retype: a field name present in both
 * the current and next CollectionDefinition whose `type` value differs.
 * Retype would silently corrupt stored data; callers must drop + re-add instead.
 */
final class BlockedSchemaChangeException extends \RuntimeException
{
    public static function retype(string $fieldName, string $fromType, string $toType): self
    {
        return new self(sprintf(
            "Cannot retype field '%s' from '%s' to '%s' in v1."
            . " Drop and re-add the field to change its type.",
            $fieldName,
            $fromType,
            $toType,
        ));
    }
}
