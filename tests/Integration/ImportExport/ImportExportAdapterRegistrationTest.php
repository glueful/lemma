<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Content\ImportExport\LemmaContentExporter;
use App\Content\ImportExport\LemmaContentImporter;
use App\Tests\Support\LemmaTestCase;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;
use Glueful\Extensions\ImportExport\Registry\ImporterRegistry;

final class ImportExportAdapterRegistrationTest extends LemmaTestCase
{
    public function testLemmaContentExporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ExporterRegistry::class);

        self::assertInstanceOf(LemmaContentExporter::class, $registry->get('lemma.content'));
    }

    public function testLemmaContentImporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ImporterRegistry::class);

        self::assertInstanceOf(LemmaContentImporter::class, $registry->get('lemma.content'));
    }
}
