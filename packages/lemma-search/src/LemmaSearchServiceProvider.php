<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ServiceProvider;
use Glueful\Lemma\Contracts\Capability\Capability;
use Glueful\Lemma\Contracts\Capability\CapabilityRegistry;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Contracts\Search\ContentReindexer;
use Glueful\Lemma\Contracts\Search\IndexableContentReader;
use Glueful\Lemma\Search\Engine\LiveMeilisearchIndex;
use Glueful\Lemma\Search\Engine\MeilisearchBackend;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Glueful\Lemma\Search\Index\NullContentReindexer;
use Glueful\Lemma\Search\Index\ResilientContentReindexer;
use Glueful\Lemma\Search\Index\SearchContentReindexer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class LemmaSearchServiceProvider extends ServiceProvider
{
    /** @return array<string, array<string, mixed>> */
    public static function services(): array
    {
        return [
            SearchBackend::class => [
                'shared' => true, 'factory' => [self::class, 'makeSearchBackend'],
            ],
            DocumentBuilder::class => [
                'shared' => true, 'factory' => [self::class, 'makeDocumentBuilder'],
            ],
            SearchContentReindexer::class => [
                'class' => SearchContentReindexer::class, 'shared' => true, 'autowire' => true,
            ],
            ContentReindexer::class => [
                'shared' => true, 'factory' => [self::class, 'makeContentReindexer'],
            ],
        ];
    }

    public static function makeSearchBackend(ContainerInterface $container): MeilisearchBackend
    {
        $context = $container->get(ApplicationContext::class);
        $indexName = (string) config($context, 'lemma_search.index', 'lemma_content');
        $snippetLength = (int) config($context, 'lemma_search.snippet_length', 40);

        return new MeilisearchBackend(
            LiveMeilisearchIndex::fromContainer($container, $indexName),
            $snippetLength,
        );
    }

    public static function makeDocumentBuilder(ContainerInterface $container): DocumentBuilder
    {
        $context = $container->get(ApplicationContext::class);
        /** @var array<string,array<string,mixed>> $types */
        $types = (array) config($context, 'lemma_search.types', []);
        return new DocumentBuilder($types);
    }

    public static function makeContentReindexer(ContainerInterface $container): ContentReindexer
    {
        $context = $container->get(ApplicationContext::class);
        $registry = app($context, CapabilityRegistry::class);

        // Disabled ⇒ no-op reindexer (the App listener resolves this and does nothing).
        if (!$registry->isEnabled('lemma.search')) {
            return new NullContentReindexer();
        }

        $inner = new SearchContentReindexer(
            $container->get(IndexableContentReader::class),
            $container->get(DocumentBuilder::class),
            $container->get(SearchBackend::class),
            $container->get(ContentTypeReader::class),
        );

        return new ResilientContentReindexer($inner, $container->get(LoggerInterface::class));
    }

    public function register(ApplicationContext $context): void
    {
        // Package configs are NOT auto-loaded — merge the pack's own tree under 'lemma_search'.
        $this->mergeConfig('lemma_search', require __DIR__ . '/../config/lemma-search.php');
    }

    public function boot(ApplicationContext $context): void
    {
        $registry = app($context, CapabilityRegistry::class);

        $registry->register(new Capability(
            'lemma.search',
            label: 'Search',
            description: 'Public, delivery-parity content search backed by Meilisearch.',
        ));
    }
}
