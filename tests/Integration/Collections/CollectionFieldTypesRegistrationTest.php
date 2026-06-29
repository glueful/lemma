<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;

/**
 * Verifies that LemmaCollectionsServiceProvider seeds all collections.* field types
 * into the shared FieldTypeRegistry and that none of them collide with content.* keys.
 */
final class CollectionFieldTypesRegistrationTest extends LemmaTestCase
{
    /** @var list<string> */
    private const EXPECTED_TYPES = [
        'collections.text',
        'collections.longtext',
        'collections.integer',
        'collections.decimal',
        'collections.boolean',
        'collections.date',
        'collections.datetime',
        'collections.email',
        'collections.url',
        'collections.enum',
        'collections.json',
        'collections.relation',
        'collections.asset',
    ];

    private FieldTypeRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = $this->container()->get(FieldTypeRegistry::class);
    }

    public function testAllCollectionsTypesAreRegistered(): void
    {
        foreach (self::EXPECTED_TYPES as $key) {
            self::assertTrue(
                $this->registry->has($key),
                "Expected '{$key}' to be registered in the FieldTypeRegistry.",
            );
        }
    }

    public function testCollectionsDecimalKeyResolves(): void
    {
        $type = $this->registry->get('collections.decimal');
        self::assertSame('collections.decimal', $type->key());
        self::assertSame('scalar', $type->valueShape());
    }

    public function testNoCollectionsKeyCollidesWithContentKey(): void
    {
        $allKeys = array_keys($this->registry->all());
        $contentKeys    = array_filter($allKeys, static fn (string $k): bool => str_starts_with($k, 'content.'));
        $collectionsKeys = array_filter($allKeys, static fn (string $k): bool => str_starts_with($k, 'collections.'));

        // Strip prefixes and verify the bare names don't overlap across domains.
        $contentPrefixLen     = strlen('content.');
        $collectionsPrefixLen = strlen('collections.');
        $strip = static fn (int $len): \Closure => static fn (string $k): string => substr($k, $len);
        $contentBare    = array_map($strip($contentPrefixLen), $contentKeys);
        $collectionsBare = array_map($strip($collectionsPrefixLen), $collectionsKeys);

        $collisions = array_intersect($contentBare, $collectionsBare);
        self::assertEmpty(
            $collisions,
            'collections.* and content.* share bare names — keys would be ambiguous if the prefix were dropped: '
            . implode(', ', $collisions),
        );
    }

    public function testScalarTypesAreFilterableAndSortable(): void
    {
        $scalarTypes = ['collections.text', 'collections.integer', 'collections.decimal',
                        'collections.boolean', 'collections.date', 'collections.datetime',
                        'collections.email', 'collections.url', 'collections.enum'];

        foreach ($scalarTypes as $key) {
            $caps = $this->registry->get($key)->capabilities();
            self::assertTrue(
                $caps['filterable'] ?? false,
                "'{$key}' should be filterable.",
            );
            self::assertTrue(
                $caps['sortable'] ?? false,
                "'{$key}' should be sortable.",
            );
        }
    }

    public function testJsonRelationAssetAreNotFilterableOrSortable(): void
    {
        foreach (['collections.json', 'collections.relation', 'collections.asset'] as $key) {
            $caps = $this->registry->get($key)->capabilities();
            self::assertFalse(
                $caps['filterable'] ?? true,
                "'{$key}' should not be filterable.",
            );
            self::assertFalse(
                $caps['sortable'] ?? true,
                "'{$key}' should not be sortable.",
            );
        }
    }

    public function testRelationAndAssetAreFlaggedMulti(): void
    {
        foreach (['collections.relation', 'collections.asset'] as $key) {
            $caps = $this->registry->get($key)->capabilities();
            self::assertTrue(
                $caps['multi'] ?? false,
                "'{$key}' should have multi=true.",
            );
        }
    }
}
