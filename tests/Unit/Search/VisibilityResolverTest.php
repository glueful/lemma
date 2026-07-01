<?php

declare(strict_types=1);

namespace App\Tests\Unit\Search;

use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Query\VisibilityResolver;
use PHPUnit\Framework\TestCase;

final class VisibilityResolverTest extends TestCase
{
    /**
     * @param array<string,string> $slugToUuid
     * @param list<string> $publicUuids
     */
    private function types(array $slugToUuid, array $publicUuids = []): ContentTypeReader
    {
        return new class ($slugToUuid, $publicUuids) implements ContentTypeReader {
            /**
             * @param array<string,string> $map
             * @param list<string> $public
             */
            public function __construct(private array $map, private array $public)
            {
            }
            public function findUuidBySlug(string $slug): ?string
            {
                return $this->map[$slug] ?? null;
            }
            public function isPublicDelivery(string $uuid): bool
            {
                return in_array($uuid, $this->public, true);
            }
            public function schemaFor(string $uuid): ?ContentSchemaReader
            {
                return null;
            }
        };
    }

    public function testAnonymousHasNoAccess(): void
    {
        $ctx = (new VisibilityResolver($this->types([])))->resolve(null);
        self::assertFalse($ctx->allAccess);
        self::assertSame([], $ctx->scopedTypeUuids);
    }

    public function testReadContentGrantsAllAccess(): void
    {
        $ctx = (new VisibilityResolver($this->types([])))->resolve(['read:content']);
        self::assertTrue($ctx->allAccess);
    }

    public function testEmptyScopesArrayIsFullAccessKey(): void
    {
        // A key with NO scope restriction ([]) satisfies everything (ApiKeyService semantics).
        $ctx = (new VisibilityResolver($this->types([])))->resolve([]);
        self::assertTrue($ctx->allAccess);
    }

    public function testScopedSlugsResolveToUuids(): void
    {
        $resolver = new VisibilityResolver($this->types(['blog' => 'ct-blog', 'news' => 'ct-news']));
        $ctx = $resolver->resolve(['read:content:blog', 'read:content:news', 'read:other']);
        self::assertFalse($ctx->allAccess);
        self::assertContains('ct-blog', $ctx->scopedTypeUuids);
        self::assertContains('ct-news', $ctx->scopedTypeUuids);
    }

    public function testIsTypeAccessibleMirrorsDeliveryParity(): void
    {
        $resolver = new VisibilityResolver(
            $this->types(['blog' => 'ct-blog', 'secret' => 'ct-secret'], publicUuids: ['ct-pub']),
        );

        $scoped = $resolver->resolve(['read:content:blog']);
        self::assertTrue($resolver->isTypeAccessible($scoped, 'ct-blog'));    // scoped
        self::assertTrue($resolver->isTypeAccessible($scoped, 'ct-pub'));     // public_delivery
        self::assertFalse($resolver->isTypeAccessible($scoped, 'ct-secret')); // neither → 403

        $all = $resolver->resolve(['read:content']);
        self::assertTrue($resolver->isTypeAccessible($all, 'ct-secret'));     // all-access
    }
}
