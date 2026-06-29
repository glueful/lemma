<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Collections\CollectionManager;
use Glueful\Lemma\Collections\Repositories\CollectionDefinitionRepository;

/**
 * The per-collection access policy survives a create → load round-trip through the
 * collection_definitions.access_policy column.
 */
final class AccessPolicyPersistenceTest extends LemmaTestCase
{
    public function testCreateWithAccessPolicyRoundTripsThroughTheDatabase(): void
    {
        $this->manager()->create([
            'name' => 'articles',
            'label' => 'Articles',
            'fields' => [['name' => 'title', 'type' => 'collections.text', 'settings' => []]],
            'access' => ['read' => 'public', 'write' => 'scoped', 'delete' => 'scoped'],
        ], 'admin', 'u-1');

        $loaded = $this->repo()->findByName('articles');

        self::assertNotNull($loaded);
        self::assertSame('public', $loaded->accessPolicy->read);
        self::assertSame('scoped', $loaded->accessPolicy->write);
        self::assertSame('scoped', $loaded->accessPolicy->delete);
    }

    public function testCreateWithoutAccessDefaultsToAllScoped(): void
    {
        $this->manager()->create([
            'name' => 'secrets',
            'label' => 'Secrets',
            'fields' => [['name' => 'value', 'type' => 'collections.text', 'settings' => []]],
        ], 'admin', 'u-1');

        $loaded = $this->repo()->findByName('secrets');

        self::assertNotNull($loaded);
        self::assertSame('scoped', $loaded->accessPolicy->read);
        self::assertSame('scoped', $loaded->accessPolicy->write);
        self::assertSame('scoped', $loaded->accessPolicy->delete);
    }

    private function manager(): CollectionManager
    {
        return $this->container()->get(CollectionManager::class);
    }

    private function repo(): CollectionDefinitionRepository
    {
        return $this->container()->get(CollectionDefinitionRepository::class);
    }
}
