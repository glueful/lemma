<?php

declare(strict_types=1);

namespace App\Content\Delivery;

use App\Content\Schema\FieldDefinition;

/**
 * Resolves a reference field's filter input values (uuids and/or slugs) to a deduped list of
 * published target entry uuids in the given delivery locale.
 */
interface ReferenceTargetResolver
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function resolve(FieldDefinition $field, string $locale, array $values): array;
}
