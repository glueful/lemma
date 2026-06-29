<?php

declare(strict_types=1);

namespace App\Tests\Integration\Collections;

use PHPUnit\Framework\TestCase;

/**
 * Proves that the lemma-collections pack source never references the App\ namespace.
 *
 * Packs must depend only on glueful/framework + glueful/lemma-contracts (and any
 * pack-specific deps). Any reference to App\ would couple the pack to the host
 * application and break the composer-install / removability guarantee.
 *
 * The regex `/(^|[^\w])App\\/m` is identical to scripts/check-pack-boundaries.php
 * so that this test and the CI guard agree on what constitutes a violation.
 */
final class NoAppReferencesTest extends TestCase
{
    /**
     * Every .php file under packages/lemma-collections/src must be free of App\ references.
     * On failure, the assertion message lists every offending file:line pair.
     */
    public function testNoAppReferencesInPackSource(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/packages/lemma-collections/src';

        self::assertDirectoryExists($srcDir, 'lemma-collections src directory must exist');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $violations = [];

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $content = (string) file_get_contents($file->getPathname());

            // Quick whole-file check with the same regex as the boundary guard script.
            // The /m flag makes ^ anchor to the start of each line.
            if (preg_match('/(^|[^\\w])App\\\\/m', $content) !== 1) {
                continue;
            }

            // Slow path: find the exact line numbers for the failure message.
            $relativePath = ltrim(str_replace($srcDir, '', $file->getPathname()), '/\\');
            foreach (explode("\n", $content) as $lineIndex => $line) {
                if (preg_match('/(^|[^\\w])App\\\\/', $line) === 1) {
                    $violations[] = $relativePath . ':' . ($lineIndex + 1) . ' — ' . trim($line);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'lemma-collections/src must not reference App\\ namespace (pack boundary violation):'
                . "\n  " . implode("\n  ", $violations),
        );
    }
}
