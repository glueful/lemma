<?php

declare(strict_types=1);

namespace App\Tests\Integration\ImportExport;

use App\Content\ImportExport\LemmaContentExporter;
use App\Tests\Support\LemmaTestCase;
use Glueful\Extensions\ImportExport\Registry\ExporterRegistry;

final class ImportExportAdapterRegistrationTest extends LemmaTestCase
{
    public function testLemmaContentExporterIsRegisteredWithImportExportRegistry(): void
    {
        $registry = $this->container()->get(ExporterRegistry::class);

        self::assertInstanceOf(LemmaContentExporter::class, $registry->get('lemma.content'));
    }
}
