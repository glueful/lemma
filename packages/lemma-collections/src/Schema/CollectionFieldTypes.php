<?php

declare(strict_types=1);

namespace Glueful\Lemma\Collections\Schema;

use Glueful\Lemma\Contracts\Schema\FieldTypeDefinition;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;

/**
 * Registers the core collection field types under the "collections.*" namespace.
 *
 * Task 4 will complete the full set; this task seeds `collections.text` only
 * to prove the provider wiring.
 */
final class CollectionFieldTypes
{
    public static function register(FieldTypeRegistry $registry): void
    {
        foreach (self::definitions() as $definition) {
            $registry->register($definition);
        }
    }

    /** @return list<FieldTypeDefinition> */
    private static function definitions(): array
    {
        return [
            self::make('collections.text', 'Text', 'scalar', 'text-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
        ];
    }

    /**
     * @param array{filterable:bool,sortable:bool,indexable:bool,multi:bool,localized:bool} $capabilities
     */
    private static function make(
        string $key,
        string $label,
        string $valueShape,
        string $adminWidget,
        array $capabilities,
    ): FieldTypeDefinition {
        return new class ($key, $label, $valueShape, $adminWidget, $capabilities) implements FieldTypeDefinition {
            /**
             * @param array{filterable:bool,sortable:bool,indexable:bool,multi:bool,localized:bool} $caps
             */
            public function __construct(
                private readonly string $key,
                private readonly string $label,
                private readonly string $valueShape,
                private readonly string $adminWidget,
                private readonly array $caps,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function valueShape(): string
            {
                return $this->valueShape;
            }

            public function validationRules(): array
            {
                return [];
            }

            public function adminWidget(): string
            {
                return $this->adminWidget;
            }

            public function capabilities(): array
            {
                return $this->caps;
            }
        };
    }
}
