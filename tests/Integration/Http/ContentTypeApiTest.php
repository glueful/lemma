<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\ContentTypeController;
use App\Content\Repositories\ContentTypeRepository;
use App\Tests\Support\LemmaTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ContentTypeApiTest extends LemmaTestCase
{
    private function controller(): ContentTypeController
    {
        // Resolve from the container so the autowired QueueManager + ApplicationContext
        // (used to enqueue the filter-index reconciliation job) are injected.
        return $this->container()->get(ContentTypeController::class);
    }

    private function json(array $body): Request
    {
        return new Request([], [], [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    }

    public function testStoreCreatesType(): void
    {
        $resp = $this->controller()->store($this->json([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]));
        self::assertSame(201, $resp->getStatusCode());
        self::assertNotNull((new ContentTypeRepository($this->connection()))->findBySlug('post'));
    }

    public function testStoreRejectsBadSchema(): void
    {
        $resp = $this->controller()->store($this->json([
            'slug' => 'post', 'name' => 'Post',
            'schema' => [['name' => 'price', 'type' => 'number', 'filterable' => true]], // missing filter_type
        ]));
        self::assertSame(422, $resp->getStatusCode());
    }

    public function testShowNotFound(): void
    {
        self::assertSame(404, $this->controller()->show(new Request(), 'nope')->getStatusCode());
    }
}
