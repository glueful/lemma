<?php

declare(strict_types=1);

namespace App\Content\ImportExport;

use App\Content\Repositories\ContentTypeRepository;
use App\Content\Repositories\EntryRepository;
use App\Content\Schema\ContentTypeSchema;
use App\Content\Services\PublishService;
use App\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;

use function config;

/**
 * Imports a CSV file into entries of one content type.
 *
 * Unlike {@see LemmaContentImporter} (a low-level bundle of raw table rows), this is a high-level
 * adapter: each CSV row becomes a new entry. The caller supplies an `options` bag —
 * `content_type` (slug), `mapping` (field => CSV column header), and optional `locale`/`publish` —
 * and each row is mapped, type-coerced (CSV values are all strings), validated against the schema,
 * then written via the normal create-draft path. `dry_run` validates and reports without writing.
 *
 * v1 is create-only: every row makes a new entry (no upsert by a stable key).
 */
final class CsvContentImporter implements ImporterInterface, RetryableAdapterInterface
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
        private readonly EntryRepository $entries,
        private readonly PublishService $publisher,
    ) {
    }

    public function key(): string
    {
        return 'csv.content';
    }

    public function label(): string
    {
        return 'CSV';
    }

    public function supports(ImportSource $source): bool
    {
        return $source->path !== '' && strtolower((string) pathinfo($source->path, PATHINFO_EXTENSION)) === 'csv';
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        $slug = $this->stringOption($options->options, 'content_type');
        $mapping = $this->mappingOption($options->options);
        if ($slug === '') {
            throw new \InvalidArgumentException('A target content_type is required.');
        }
        if ($mapping === []) {
            throw new \InvalidArgumentException('A column mapping is required.');
        }
        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            throw new \InvalidArgumentException(sprintf('Unknown content type "%s".', $slug));
        }

        $csv = $this->readCsv($this->resolveSourcePath($source->disk, $source->path));
        foreach ($mapping as $field => $column) {
            if (!in_array($column, $csv['header'], true)) {
                throw new \InvalidArgumentException(
                    sprintf('CSV has no column "%s" (mapped to field "%s").', $column, $field),
                );
            }
        }

        $total = count($csv['rows']);
        $batchSize = max(1, $options->batchSize);
        $batches = [];
        for ($offset = 0, $sequence = 1; $offset < $total; $offset += $batchSize, $sequence++) {
            $batches[] = new ImportBatch(
                uuid: $this->batchUuid($sequence, $offset),
                jobUuid: 'pending',
                sequence: $sequence,
                offset: $offset,
                limit: $batchSize,
            );
        }

        return new ImportPlan($total, $batches, retryable: true, metadata: [
            'format' => 'csv',
            'content_type' => $slug,
        ]);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        $options = $context->options;
        $slug = $this->stringOption($options, 'content_type');
        $mapping = $this->mappingOption($options);
        $locale = $this->stringOption($options, 'locale');
        $locale = $locale !== '' ? $locale : 'en';
        $publish = (bool) ($options['publish'] ?? false);

        $type = $this->types->findBySlug($slug);
        if ($type === null) {
            throw new \RuntimeException(sprintf('Content type "%s" no longer exists.', $slug));
        }
        $typeUuid = (string) $type['uuid'];
        $schemaVersion = (int) ($type['schema_version'] ?? 1);
        $schema = $this->types->schemaFor($typeUuid);
        /** @var array<string,\App\Content\Schema\FieldDefinition> $fieldsByName */
        $fieldsByName = [];
        foreach ($schema->fields() as $field) {
            $fieldsByName[$field->name] = $field;
        }

        $rows = array_slice($this->recordsForJob($context->jobUuid), $batch->offset, $batch->limit);
        $errors = [];
        $processed = 0;

        foreach ($rows as $index => $row) {
            $line = $batch->offset + $index + 1;
            try {
                $payload = $this->mapRow($row, $mapping, $fieldsByName);
                $clean = $this->validator->validate($schema, $payload);
                if ($context->mode === 'commit') {
                    $entryUuid = $this->entries->createEntry($typeUuid, $locale, $schemaVersion, $context->actorUuid);
                    $this->entries->saveDraft($entryUuid, $locale, $clean, $schemaVersion, 0, $context->actorUuid);
                    if ($publish) {
                        $this->publisher->publish($entryUuid, $locale, $context->actorUuid);
                    }
                }
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'record_number' => $line,
                    'severity' => 'error',
                    'code' => 'csv_import_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return new ImportBatchResult($processed, count($errors), $errors, ['mode' => $context->mode]);
    }

    public function retryable(): bool
    {
        return true;
    }

    /**
     * Map a CSV row to a field payload, coercing each string value to its schema field's type
     * (CSV cells are always strings; FieldValidator type-checks strictly).
     *
     * @param array<string,string> $row
     * @param array<string,string> $mapping field name => CSV column header
     * @param array<string,\App\Content\Schema\FieldDefinition> $fieldsByName
     * @return array<string,mixed>
     */
    private function mapRow(array $row, array $mapping, array $fieldsByName): array
    {
        $payload = [];
        foreach ($mapping as $field => $column) {
            $def = $fieldsByName[$field] ?? null;
            if ($def === null) {
                continue; // mapped to a field that isn't in the schema — ignore
            }
            $payload[$field] = $this->coerce($def->type, (string) ($row[$column] ?? ''));
        }
        return $payload;
    }

    /** Coerce a raw CSV string to the field's type; an empty cell becomes null (absent). */
    private function coerce(string $type, string $raw): mixed
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        return match ($type) {
            'number' => is_numeric($raw)
                ? ((string) (int) $raw === $raw ? (int) $raw : (float) $raw)
                : $raw, // non-numeric stays a string so the validator reports "must be a number"
            'boolean' => in_array(strtolower($raw), ['true', '1', 'yes', 'on'], true),
            'json' => is_array($decoded = json_decode($raw, true)) ? $decoded : $raw,
            default => $raw, // string/text/datetime/enum/reference/asset
        };
    }

    /**
     * Read a CSV file into a header row + a list of associative rows keyed by column header.
     *
     * @return array{header: list<string>, rows: list<array<string,string>>}
     */
    private function readCsv(string $path): array
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Could not open CSV file "%s".', $path));
        }
        try {
            // Pass $escape explicitly — its default is deprecated/changing in PHP 8.4+.
            $header = fgetcsv($handle, null, ',', '"', '');
            if (!is_array($header)) {
                return ['header' => [], 'rows' => []];
            }
            $header = array_map(static fn($h): string => (string) $h, $header);

            $rows = [];
            while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if (!is_array($data) || $data === [null]) {
                    continue; // skip blank lines
                }
                $assoc = [];
                foreach ($header as $i => $column) {
                    $assoc[$column] = isset($data[$i]) ? (string) $data[$i] : '';
                }
                $rows[] = $assoc;
            }
            return ['header' => $header, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /**
     * The job's CSV source rows (from the import_export_files table).
     *
     * @return list<array<string,string>>
     */
    private function recordsForJob(string $jobUuid): array
    {
        $file = $this->db->table('import_export_files')
            ->where('job_uuid', '=', $jobUuid)
            ->where('role', '=', 'source')
            ->orderBy('id')
            ->first();
        if ($file === null) {
            throw new \RuntimeException(sprintf('Import source file for job "%s" was not found.', $jobUuid));
        }

        return $this->readCsv($this->resolveSourcePath((string) $file['disk'], (string) $file['path']))['rows'];
    }

    private function resolveSourcePath(string $disk, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        $roots = config($this->context, 'import_export.source_roots', []);
        $root = is_array($roots) && isset($roots[$disk]) && is_string($roots[$disk]) && $roots[$disk] !== ''
            ? $roots[$disk]
            : $this->context->getBasePath() . DIRECTORY_SEPARATOR . $disk;

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    /** @param array<string,mixed> $options */
    private function stringOption(array $options, string $key): string
    {
        return isset($options[$key]) && is_string($options[$key]) ? $options[$key] : '';
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,string> field name => CSV column header
     */
    private function mappingOption(array $options): array
    {
        $raw = $options['mapping'] ?? null;
        if (!is_array($raw)) {
            return [];
        }
        $mapping = [];
        foreach ($raw as $field => $column) {
            if (is_string($field) && is_string($column) && $field !== '' && $column !== '') {
                $mapping[$field] = $column;
            }
        }
        return $mapping;
    }

    private function batchUuid(int $sequence, int $offset): string
    {
        return substr(hash('sha256', "csv.content.import:{$sequence}:{$offset}"), 0, 12);
    }
}
