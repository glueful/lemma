<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Http\DTOs\CreateContentTypeData;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\LemmaTestCase;
use Glueful\Validation\Contracts\RequestData;
use Glueful\Validation\RequestDataHydrator;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeApiTest extends LemmaTestCase
{
    private function controller(): ContentTypeController
    {
        // Resolve from the container so the autowired QueueManager + ApplicationContext
        // (used to enqueue the filter-index reconciliation job) are injected.
        return $this->container()->get(ContentTypeController::class);
    }

    /**
     * Hydrate a request DTO exactly as the router would, so DTO validation is exercised
     * before the controller sees it. (Lemma DTOs use only built-in rules, so no registry.)
     *
     * @param  class-string<RequestData> $dtoClass
     * @param  array<string,mixed>       $body
     */
    private function hydrate(string $dtoClass, array $body): RequestData
    {
        return (new RequestDataHydrator())->hydrate($dtoClass, $body);
    }

    public function testStoreCreatesType(): void
    {
        $resp = $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'post', 'name' => 'Post',
                'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
            ]),
            new Request(),
        );
        self::assertSame(201, $resp->getStatusCode());
        self::assertNotNull((new ContentTypeRepository($this->connection()))->findBySlug('post'));
    }

    public function testStoreRejectsBadSchema(): void
    {
        // Structural hydration passes (each field def has name + type); the semantic rule
        // (a filterable field must declare filter_type) is the domain SchemaParseException → 422.
        $resp = $this->controller()->store(
            $this->hydrate(CreateContentTypeData::class, [
                'slug' => 'post', 'name' => 'Post',
                'schema' => [['name' => 'price', 'type' => 'number', 'filterable' => true]], // missing filter_type
            ]),
            new Request(),
        );
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testStoreRejectsBadSlugAtHydration(): void
    {
        // Structural validation now lives in the DTO: a non-lowercase slug fails hydration
        // with a standard 422 before the controller runs.
        $this->expectException(ValidationException::class);
        $this->hydrate(CreateContentTypeData::class, ['slug' => 'Bad Slug', 'name' => 'X', 'schema' => []]);
    }

    public function testShowNotFound(): void
    {
        self::assertSame(404, $this->controller()->show(new Request(), 'nope')->getStatusCode());
    }
}
