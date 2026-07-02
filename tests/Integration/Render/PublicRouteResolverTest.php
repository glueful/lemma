<?php

declare(strict_types=1);

namespace App\Tests\Integration\Render;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Seo\RedirectRepository;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\PublicRouteResolver;

final class PublicRouteResolverTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function setUp(): void
    {
        parent::setUp();
        // Locale-variant parsing consults the i18n registry (spec §3: "active locale
        // codes"); the harness DB ships none, so register en (default) + fr.
        $pdo = $this->connection()->getPDO();
        $pdo->exec("DELETE FROM i18n_locales WHERE code IN ('en', 'fr')");
        $now = gmdate('Y-m-d H:i:s');
        foreach ([['en', true], ['fr', false]] as [$code, $isDefault]) {
            $this->connection()->table('i18n_locales')->insert([
                'uuid' => \Glueful\Helpers\Utils::generateNanoID(),
                'code' => $code,
                'name' => strtoupper($code),
                'enabled' => true,
                'is_default' => $isDefault,
                'fallback_locale' => $isDefault ? null : 'en',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function resolver(): PublicRouteResolver
    {
        return $this->container()->get(PublicRouteResolver::class);
    }

    public function testPublishedPathResolvesToDeliveryShapedContent(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/blog/hello'); // default-locale variant
        self::assertSame('content', $r['kind']);
        self::assertSame('en', $r['locale']);
        self::assertSame($entry, $r['content']['uuid']);
        self::assertArrayHasKey('seo', $r['content']); // stamped like the delivery API
        self::assertSame('Hello', $r['content']['fields']['title']);
    }

    public function testLocaleVariantPath(): void
    {
        $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolvePath('/fr/blog/bonjour');
        self::assertSame('content', $r['kind']);
        self::assertSame('fr', $r['locale']);
        self::assertSame('Bonjour', $r['content']['fields']['title']);
    }

    public function testNormalizationRedirectsComeBeforeLookup(): void
    {
        $this->seedBilingualPublishedEntry();
        foreach (['/blog//hello' => '/blog/hello', '/blog/hello/' => '/blog/hello'] as $raw => $canonical) {
            $r = $this->resolver()->resolvePath($raw);
            self::assertSame('redirect', $r['kind'], $raw);
            self::assertSame(['location' => $canonical, 'status' => 301], $r['redirect']);
        }
    }

    public function testArityAndLocaleEdges(): void
    {
        $this->seedBilingualPublishedEntry();
        self::assertSame('not_found', $this->resolver()->resolvePath('/en/blog')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/only-one')['kind']);
        self::assertSame('not_found', $this->resolver()->resolvePath('/a/b/c/d')['kind']);
    }

    public function testResolveEntryForHomepage(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $r = $this->resolver()->resolveEntry($entry);
        self::assertSame('content', $r['kind']);
        self::assertArrayHasKey('seo', $r['content']);
        self::assertSame('not_found', $this->resolver()->resolveEntry('nope00000000')['kind']);
    }

    public function testResolveEntryRoutelessIsNotFound(): void
    {
        $entry = $this->seedBilingualPublishedEntry();
        $this->container()->get(\App\Content\Repositories\RouteRepository::class)->remove($entry, 'en');
        self::assertSame('not_found', $this->resolver()->resolveEntry($entry)['kind']);
    }

    public function testNonPublicTypeIsNotFoundEvenWithARoute(): void
    {
        // render is anonymous; a route existing is not enough (spec §3 visibility pin).
        $this->seedPublishedEntryInType('secret-doc', false, 'en', 'classified', 'Classified');
        self::assertSame('not_found', $this->resolver()->resolvePath('/secret-doc/classified')['kind']);
    }

    public function testExternalRedirectFlowsThrough(): void
    {
        $this->seedBilingualPublishedEntry();
        $typeUuid = (string) $this->container()->get(ContentTypeRepository::class)->findBySlug('blog')['uuid'];
        (new RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $typeUuid,
            'locale' => 'en',
            'source_slug' => 'old-post',
            'target_url' => 'https://elsewhere.test/x',
            'status' => 302,
        ]);

        $r = $this->resolver()->resolvePath('/blog/old-post');
        self::assertSame('redirect', $r['kind']);
        self::assertSame(['location' => 'https://elsewhere.test/x', 'status' => 302], $r['redirect']);
    }

    public function testBrokenInternalRedirectIsGone(): void
    {
        // Internal redirect target that is draft-only → RouteResolver marks it broken → gone.
        $this->seedBilingualPublishedEntry();
        $types = $this->container()->get(ContentTypeRepository::class);
        $entries = $this->container()->get(EntryRepository::class);
        $typeUuid = (string) $types->findBySlug('blog')['uuid'];
        $draft = $entries->createEntry($typeUuid, 'en', 1, 'user00000001');
        $entries->saveDraft($draft, 'en', ['title' => 'Draft'], 1, 0, 'user00000001');

        (new RedirectRepository($this->connection()))->create([
            'content_type_uuid' => $typeUuid,
            'locale' => 'en',
            'source_slug' => 'moved-away',
            'target_content_type_uuid' => $typeUuid,
            'target_locale' => 'en',
            'target_entry_uuid' => $draft,
            'status' => 301,
        ]);

        self::assertSame('gone', $this->resolver()->resolvePath('/blog/moved-away')['kind']);
    }
}
