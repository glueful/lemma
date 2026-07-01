<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo;

use App\Tests\Integration\Seo\Concerns\SeedsPublishedContent;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Delivery\ContentDeliveryReader;
use Glueful\Lemma\Seo\Cache\SitemapCache;
use Glueful\Lemma\Seo\Http\Controllers\RobotsController;
use Glueful\Lemma\Seo\Http\Controllers\SitemapController;
use Glueful\Lemma\Seo\Sitemap\SitemapBuilder;
use Symfony\Component\HttpFoundation\Request;

final class SitemapEndpointTest extends LemmaTestCase
{
    use SeedsPublishedContent;

    protected function setUp(): void
    {
        parent::setUp();
        // The array CacheStore is a shared singleton across test methods; start each test
        // with an empty sitemap cache so the builder renders fresh, not a polluted entry.
        $this->container()->get(SitemapCache::class)->forgetAll();
    }

    public function testSitemapServesUrlsetWhenOriginConfigured(): void
    {
        $this->seedBilingualPublishedEntry(); // origin from LEMMA_PUBLIC_URL_BASE=https://site.test
        $resp = $this->container()->get(SitemapController::class)->index(new Request());

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('application/xml; charset=UTF-8', $resp->headers->get('Content-Type'));
        $body = (string) $resp->getContent();
        self::assertStringContainsString('<urlset', $body);
        self::assertStringContainsString('https://site.test/en/blog/hello', $body);
    }

    public function testSitemap409WhenOriginMissing(): void
    {
        // Construct the controller directly with an empty-origin builder — no runtime
        // config-override helper needed.
        $builder = new SitemapBuilder(
            $this->container()->get(ContentDeliveryReader::class),
            $this->container()->get(SitemapCache::class),
            '', // no origin
        );
        $resp = (new SitemapController($builder))->index(new Request());
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSitemapRoutesAreRegistered(): void
    {
        self::assertNotNull($this->findRoute('GET', '/sitemap.xml'), '/sitemap.xml must be registered');
        self::assertNotNull($this->findRoute('GET', '/sitemap/{n}.xml'), 'page route must be registered');
    }

    public function testRobotsServesGroupsAndSitemapLine(): void
    {
        $resp = $this->container()->get(RobotsController::class)->show();

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('text/plain; charset=UTF-8', $resp->headers->get('Content-Type'));
        $body = (string) $resp->getContent();
        self::assertStringContainsString('User-agent: *', $body);
        self::assertStringContainsString('Sitemap: https://site.test/sitemap.xml', $body);
    }

    public function testRobotsRouteIsRegistered(): void
    {
        self::assertNotNull($this->findRoute('GET', '/robots.txt'), '/robots.txt must be registered');
    }
}
