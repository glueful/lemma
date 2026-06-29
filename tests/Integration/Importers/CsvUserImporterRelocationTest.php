<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Tests\Support\LemmaTestCase;
use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Lemma\Importers\CsvUserImporter;

final class CsvUserImporterRelocationTest extends LemmaTestCase
{
    public function testCsvUserImporterIsResolvableFromContainer(): void
    {
        $importer = $this->container()->get(CsvUserImporter::class);
        self::assertInstanceOf(ImporterInterface::class, $importer);
    }

    public function testCsvUserImporterKeyIsCorrect(): void
    {
        $importer = $this->container()->get(CsvUserImporter::class);
        self::assertSame('csv.users', $importer->key());
    }
}
