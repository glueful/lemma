<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

final class CapabilityRegistrationTest extends TestCase
{
    public function testProviderFqcnDeclaredInPackComposer(): void
    {
        $path = dirname(__DIR__, 3) . '/packages/lemma-search/composer.json';
        $composer = json_decode((string) file_get_contents($path), true);
        self::assertSame(
            'Glueful\\Lemma\\Search\\LemmaSearchServiceProvider',
            $composer['extra']['glueful']['provider'] ?? null,
        );
    }

    public function testCapabilityValueObjectIdentity(): void
    {
        $cap = new Capability('lemma.search', label: 'Search');
        self::assertSame('lemma.search', $cap->id);
        self::assertInstanceOf(CapabilityRegistry::class, new class implements CapabilityRegistry {
            public function register(Capability $c): void
            {
            }
            public function all(): array
            {
                return [];
            }
            public function enabled(): array
            {
                return [];
            }
            public function isEnabled(string $id): bool
            {
                return true;
            }
        });
    }
}
