<?php

declare(strict_types=1);

namespace Glueful\Lemma\Search\Console;

use Glueful\Console\BaseCommand;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;
use Glueful\Lemma\Search\Engine\SearchBackend;
use Glueful\Lemma\Search\Index\DocumentBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'search:status', description: 'Report search backend health and configuration warnings.')]
final class StatusCommand extends BaseCommand
{
    public function __construct(
        private readonly SearchBackend $backend,
        private readonly DocumentBuilder $builder,
        private readonly ContentTypeReader $types,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $healthy = $this->backend->health();
        $output->writeln($healthy
            ? '<info>Backend: reachable, index present.</info>'
            : '<error>Backend: UNREACHABLE (GET /v1/search will return 503).</error>');

        // Per-type config-field validation (only configured types need checking).
        /** @var array<string,mixed> $typeConfig */
        $typeConfig = (array) config($this->getContext(), 'lemma_search.types', []);
        $warnings = [];
        foreach (array_keys($typeConfig) as $slug) {
            $slug = (string) $slug;
            $uuid = $this->types->findUuidBySlug($slug);
            if ($uuid === null) {
                $warnings[] = "[{$slug}] configured type has no matching content type (skipped).";
                continue;
            }
            $schema = $this->types->schemaFor($uuid);
            if ($schema !== null) {
                $warnings = array_merge($warnings, $this->builder->validate($slug, $schema));
            }
        }

        foreach ($warnings as $w) {
            $output->writeln('<comment>' . $w . '</comment>');
        }

        // Visibility drift is a documented edge: flipping a type's public_delivery flag needs a
        // reindex to take effect (public_delivery is denormalized into each doc at index time).
        $output->writeln(
            '<comment>Note: after changing a content type\'s public_delivery flag, run '
            . '`php glueful search:reindex --type=<slug>` for search visibility to match delivery.</comment>',
        );

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
