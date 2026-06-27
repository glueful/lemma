<?php

declare(strict_types=1);

namespace App\Content\ImportExport;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use App\ImportExport\AbstractCsvImporter;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;

/**
 * Imports a CSV file into entries of one content type — one entry per row.
 *
 * The {@see AbstractCsvImporter} base handles reading/batching/error-reporting; this class supplies
 * the content specifics: the `options` bag (`content_type` slug, `mapping` of field => column, and
 * optional `locale`/`publish`), and a per-row map → type-coerce → validate → create-draft (publish
 * optional). v1 is create-only (no upsert by a stable key).
 */
final class CsvContentImporter extends AbstractCsvImporter
{
    public function __construct(
        ApplicationContext $context,
        Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
        private readonly EntryRepository $entries,
        private readonly PublishService $publisher,
    ) {
        parent::__construct($context, $db);
    }

    public function key(): string
    {
        return 'csv.content';
    }

    public function label(): string
    {
        return 'CSV';
    }

    protected function validatePlan(array $header, ImportOptions $options): void
    {
        $slug = $this->stringOption($options->options, 'content_type');
        $mapping = $this->mappingOption($options->options);
        if ($slug === '') {
            throw new \InvalidArgumentException('A target content_type is required.');
        }
        if ($mapping === []) {
            throw new \InvalidArgumentException('A column mapping is required.');
        }
        if ($this->types->findBySlug($slug) === null) {
            throw new \InvalidArgumentException(sprintf('Unknown content type "%s".', $slug));
        }
        foreach ($mapping as $field => $column) {
            if (!in_array($column, $header, true)) {
                throw new \InvalidArgumentException(
                    sprintf('CSV has no column "%s" (mapped to field "%s").', $column, $field),
                );
            }
        }
    }

    protected function planMetadata(ImportOptions $options): array
    {
        return ['format' => 'csv', 'content_type' => $this->stringOption($options->options, 'content_type')];
    }

    protected function prepare(ImportContext $context): array
    {
        $slug = $this->stringOption($context->options, 'content_type');
        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            throw new \RuntimeException(sprintf('Content type "%s" no longer exists.', $slug));
        }
        $typeUuid = (string) $type['uuid'];
        $schema = $this->types->schemaFor($typeUuid);
        $fieldsByName = [];
        foreach ($schema->fields() as $field) {
            $fieldsByName[$field->name] = $field;
        }
        $locale = $this->stringOption($context->options, 'locale');

        return [
            'typeUuid' => $typeUuid,
            'schemaVersion' => (int) ($type['schema_version'] ?? 1),
            'schema' => $schema,
            'fieldsByName' => $fieldsByName,
            'mapping' => $this->mappingOption($context->options),
            'locale' => $locale !== '' ? $locale : 'en',
            'publish' => (bool) ($context->options['publish'] ?? false),
        ];
    }

    protected function importRow(array $row, array $prepared, ImportContext $context): void
    {
        $payload = [];
        foreach ($prepared['mapping'] as $field => $column) {
            $def = $prepared['fieldsByName'][$field] ?? null;
            if ($def === null) {
                continue; // mapped to a field that isn't in the schema — ignore
            }
            $payload[$field] = $this->coerce($def->type, (string) ($row[$column] ?? ''));
        }

        $clean = $this->validator->validate($prepared['schema'], $payload);
        if ($context->mode === 'commit') {
            $entryUuid = $this->entries->createEntry(
                $prepared['typeUuid'],
                $prepared['locale'],
                $prepared['schemaVersion'],
                $context->actorUuid,
            );
            $this->entries->saveDraft(
                $entryUuid,
                $prepared['locale'],
                $clean,
                $prepared['schemaVersion'],
                0,
                $context->actorUuid,
            );
            if ($prepared['publish']) {
                $this->publisher->publish($entryUuid, $prepared['locale'], $context->actorUuid);
            }
        }
    }

    protected function errorCode(): string
    {
        return 'csv_import_failed';
    }
}
