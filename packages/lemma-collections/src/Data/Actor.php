<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Data;

/**
 * Readonly value object representing the actor that performed a data mutation.
 *
 * type: one of 'api_key', 'user', or 'admin'
 * id:   the actor's public identifier (UUID or key ID); null for anonymous/system actors
 */
final readonly class Actor
{
    public function __construct(
        public string $type,
        public ?string $id,
    ) {
    }
}
