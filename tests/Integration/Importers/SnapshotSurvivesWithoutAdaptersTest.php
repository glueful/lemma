<?php

declare(strict_types=1);

namespace App\Tests\Integration\Importers;

use App\Content\ImportExport\LemmaContentExporter;
use App\Content\ImportExport\LemmaContentImporter;
use App\Tests\Support\LemmaTestCase;

final class SnapshotSurvivesWithoutAdaptersTest extends LemmaTestCase
{
    public function testSnapshotEngineResolvesIndependentlyOfThePack(): void
    {
        // The snapshot engine is core-owned; it must resolve from the container regardless of
        // the importers pack, and must NOT reference any Glueful\Lemma\Importers\* class.
        self::assertInstanceOf(LemmaContentExporter::class, $this->container()->get(LemmaContentExporter::class));
        self::assertInstanceOf(LemmaContentImporter::class, $this->container()->get(LemmaContentImporter::class));

        foreach ([LemmaContentExporter::class, LemmaContentImporter::class] as $cls) {
            $src = (string) file_get_contents((new \ReflectionClass($cls))->getFileName());
            self::assertStringNotContainsString('Glueful\\Lemma\\Importers', $src, "$cls must not depend on the pack");
        }
    }
}
