<?php

declare(strict_types=1);

namespace App\Tests\Integration\Search;

use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Http\SearchController;
use Glueful\Lemma\Search\Query\Hit;
use Glueful\Lemma\Search\Query\SearchRequest;
use Glueful\Lemma\Search\Query\SearchResults;
use Glueful\Lemma\Search\Query\VisibilityResolver;
use Symfony\Component\HttpFoundation\Request;

/**
 * The public search endpoint. The kernel has no runtime service-swap, so (like SEO's
 * SitemapEndpointTest) the controller is constructed directly with a FAKE SearchBackend
 * over the real ContentTypeReader/test DB. Route registration is asserted separately.
 */
final class SearchEndpointTest extends LemmaTestCase
{
    /**
     * @param list<Hit> $hits
     */
    private function fakeBackend(bool $healthy = true, array $hits = [], int $total = 0): SearchBackend
    {
        return new class ($healthy, $hits, $total) implements SearchBackend {
            /** @param list<Hit> $hits */
            public function __construct(private bool $healthy, private array $hits, private int $total)
            {
            }
            public function ensureIndex(): void
            {
            }
            public function upsert(iterable $documents): void
            {
            }
            public function deleteEntry(string $entryUuid, ?string $locale = null): void
            {
            }
            public function search(SearchRequest $r): SearchResults
            {
                return new SearchResults($this->hits, $this->total, $r->limit, $r->offset);
            }
            public function health(): bool
            {
                return $this->healthy;
            }
        };
    }

    private function controller(SearchBackend $backend): SearchController
    {
        /** @var ContentTypeReader $types */
        $types = $this->container()->get(ContentTypeReader::class);
        return new SearchController($backend, new VisibilityResolver($types), $types, 20, 50);
    }

    /** @param array<string,mixed> $query */
    private function request(array $query): Request
    {
        return Request::create('/v1/search', 'GET', $query, [], [], ['HTTP_ACCEPT' => 'application/json']);
    }

    public function testRouteAbsentByDefaultBecausePackIsOptIn(): void
    {
        // lemma-search is opt-in (not in the default config/extensions.php allow-list), so the
        // route is NOT registered in the standard boot. Enablement wires it up — see
        // SearchEnablementTest, which boots with the provider added.
        self::assertNull($this->findRoute('GET', '/v1/search'), '/v1/search must be opt-in, not default');
    }

    public function testHappyPathMapsHitsToDataEnvelope(): void
    {
        $hit = new Hit('e-1', 'blog', 'en', '/en/blog/x', 'Title', 'a <mark>climate</mark> b', 0.9);
        $resp = $this->controller($this->fakeBackend(hits: [$hit], total: 1))
            ->search($this->request(['q' => 'climate', 'locale' => 'en']));

        self::assertSame(200, $resp->getStatusCode());
        // Response::success() nests the payload under `data`.
        $body = json_decode((string) $resp->getContent(), true);
        self::assertSame(1, $body['data']['total']);
        self::assertSame('e-1', $body['data']['hits'][0]['uuid']);
        self::assertSame('blog', $body['data']['hits'][0]['type']);
        self::assertSame('en', $body['data']['hits'][0]['locale']);
        self::assertSame('/en/blog/x', $body['data']['hits'][0]['href']);
        self::assertSame('Title', $body['data']['hits'][0]['title']);
        self::assertStringContainsString('<mark>climate</mark>', $body['data']['hits'][0]['snippet']);
        self::assertSame(0.9, $body['data']['hits'][0]['score']);
    }

    public function testMissingQueryReturns422(): void
    {
        $c = $this->controller($this->fakeBackend());
        self::assertSame(422, $c->search($this->request(['locale' => 'en']))->getStatusCode());
        self::assertSame(422, $c->search($this->request(['q' => '', 'locale' => 'en']))->getStatusCode());
    }

    public function testMissingLocaleReturns422(): void
    {
        self::assertSame(
            422,
            $this->controller($this->fakeBackend())->search($this->request(['q' => 'hello']))->getStatusCode(),
        );
    }

    public function testUnknownTypeReturns404(): void
    {
        $resp = $this->controller($this->fakeBackend())
            ->search($this->request(['q' => 'hi', 'locale' => 'en', 'type' => 'no-such-type']));
        self::assertSame(404, $resp->getStatusCode());
    }

    public function testProvidedInaccessibleTypeReturns403ForAnonymous(): void
    {
        // A NON-public content type; anonymous request provides it → 403 (delivery parity).
        (new ContentTypeRepository($this->connection()))->create([
            'slug' => 'secret',
            'name' => 'Secret',
            'public_delivery' => false,
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);

        $resp = $this->controller($this->fakeBackend())
            ->search($this->request(['q' => 'hi', 'locale' => 'en', 'type' => 'secret']));
        self::assertSame(403, $resp->getStatusCode());
    }

    public function testUnhealthyBackendReturns503(): void
    {
        $resp = $this->controller($this->fakeBackend(healthy: false))
            ->search($this->request(['q' => 'hi', 'locale' => 'en']));
        self::assertSame(503, $resp->getStatusCode());
    }
}
