<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Content\Delivery\DeliveryRepository;
use App\Content\Delivery\EngineContentDeliveryReader;
use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Seo\CanonicalProjector;
use App\Content\Seo\PathRenderer;
use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;

final class EnumeratePublishedForSitemapTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    private function reader(): EngineContentDeliveryReader
    {
        $paths = new PathRenderer('/{locale}/{type}/{slug}', 'https://site.test', 'en');
        return new EngineContentDeliveryReader(
            new DeliveryRepository($this->connection()),
            $paths,
            new CanonicalProjector(
                new DeliveryRepository($this->connection()),
                new RouteRepository($this->connection()),
                new ContentTypeRepository($this->connection()),
                $paths,
                'en',
            ),
            new ContentTypeRepository($this->connection()),
        );
    }

    public function testEnumeratesPublishedAsAbsoluteUrlsWithAlternates(): void
    {
        $this->seedBilingualPublishedEntry();

        $page = $this->reader()->enumeratePublishedForSitemap(50000, 0);

        self::assertSame(2, $page['total']);
        self::assertCount(2, $page['items']);

        $hrefs = array_map(static fn (array $i): string => $i['href'], $page['items']);
        self::assertContains('https://site.test/en/blog/hello', $hrefs);
        self::assertContains('https://site.test/fr/blog/bonjour', $hrefs);

        foreach ($page['items'] as $item) {
            self::assertStringStartsWith('https://site.test/', $item['href']);
            $altLocales = array_map(static fn (array $a): string => $a['locale'], $item['alternates']);
            self::assertEqualsCanonicalizing(['en', 'fr'], $altLocales);
        }
    }

    public function testOffsetReturnsTheRequestedSliceNotAPage(): void
    {
        $this->seedBilingualPublishedEntry(); // 2 published rows

        // A non-multiple offset must still return exactly that slice (regression guard for
        // the old intdiv()/paginate() approach, which only worked for offset % limit == 0).
        $page = $this->reader()->enumeratePublishedForSitemap(1, 1);
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['items']);
    }
}
