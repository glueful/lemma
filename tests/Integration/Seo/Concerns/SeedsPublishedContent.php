<?php

declare(strict_types=1);

namespace App\Tests\Integration\Seo\Concerns;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ReferenceProjectionRepository;
use App\Content\Repositories\RouteRepository;
use App\Content\Repositories\VersionRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;

/**
 * Seeds a `blog` content type with one entry published in `en` (hello) and `fr` (bonjour).
 * Requires the host TestCase to provide connection()/appContext() (LemmaTestCase does).
 */
trait SeedsPublishedContent
{
    private ContentTypeRepository $seedTypes;
    private EntryRepository $seedEntries;
    private RouteRepository $seedRoutes;
    private string $seedType;

    /** @return string the entry uuid (published in en + fr). */
    protected function seedBilingualPublishedEntry(): string
    {
        $this->seedTypes = new ContentTypeRepository($this->connection());
        $this->seedEntries = new EntryRepository($this->connection(), $this->appContext(), $this->seedTypes);
        $this->seedRoutes = new RouteRepository($this->connection());
        $this->seedType = $this->seedTypes->create([
            'slug' => 'blog',
            'name' => 'Blog',
            'schema' => [['name' => 'title', 'type' => 'string', 'required' => true]],
        ]);

        $entry = $this->publishLocale('en', 'hello', 'Hello');
        $this->publishExistingLocale($entry, 'fr', 'bonjour', 'Bonjour');
        return $entry;
    }

    private function publishLocale(string $locale, string $slug, string $title): string
    {
        $entry = $this->seedEntries->createEntry($this->seedType, $locale, 1, 'user00000001');
        $this->seedEntries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->seedRoutes->assign($entry, $this->seedType, $locale, $slug);
        $this->publishSvc()->publish($entry, $locale, 'user00000001');
        return $entry;
    }

    private function publishExistingLocale(string $entry, string $locale, string $slug, string $title): void
    {
        $this->seedEntries->createLocaleDraft($entry, $locale, 1, 'user00000001');
        $this->seedEntries->saveDraft($entry, $locale, ['title' => $title], 1, 0, 'user00000001');
        $this->seedRoutes->assign($entry, $this->seedType, $locale, $slug);
        $this->publishSvc()->publish($entry, $locale, 'user00000001');
    }

    private function publishSvc(): PublishService
    {
        return new PublishService(
            $this->appContext(),
            $this->seedEntries,
            new VersionRepository($this->connection()),
            $this->seedTypes,
            new FieldValidator(),
            new ReferenceProjectionRepository($this->connection()),
        );
    }
}
