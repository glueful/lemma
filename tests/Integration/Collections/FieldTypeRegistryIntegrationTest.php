<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Schema\FieldTypeRegistry;

/**
 * Verifies that LemmaServiceProvider binds FieldTypeRegistry to the container and
 * that EditorialFieldTypes seeds the registry with all content.* type definitions.
 */
final class FieldTypeRegistryIntegrationTest extends LemmaTestCase
{
    public function testRegistryResolvesFromContainer(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        self::assertInstanceOf(FieldTypeRegistry::class, $registry);
    }

    public function testContentTextIsRegistered(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        self::assertTrue($registry->has('content.text'));
        self::assertSame('content.text', $registry->get('content.text')->key());
    }

    public function testAllKeysArePrefixedWithContent(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        foreach (array_keys($registry->all()) as $key) {
            self::assertStringStartsWith('content.', $key, "Expected key '{$key}' to start with 'content.'");
        }
    }

    public function testAllCoreContentTypesAreRegistered(): void
    {
        $registry = $this->container()->get(FieldTypeRegistry::class);

        $expectedTypes = [
            'content.string',
            'content.text',
            'content.number',
            'content.boolean',
            'content.datetime',
            'content.enum',
            'content.reference',
            'content.asset',
            'content.json',
        ];

        foreach ($expectedTypes as $type) {
            self::assertTrue($registry->has($type), "Expected '{$type}' to be registered");
        }
    }
}
