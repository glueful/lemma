<?php

/**
 * Fails if any first-party pack under packages/ declares a Composer dependency on
 * glueful/lemma (the engine app). Packs may depend on glueful/lemma-contracts,
 * glueful/framework, and pack-specific deps — never on the engine package.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$violations = [];
foreach (glob($root . '/packages/*/composer.json') ?: [] as $manifest) {
    $json = json_decode((string) file_get_contents($manifest), true);
    $name = $json['name'] ?? $manifest;
    if (($json['name'] ?? '') === 'glueful/lemma-contracts') {
        continue; // the contracts package itself is exempt
    }
    $deps = array_merge($json['require'] ?? [], $json['require-dev'] ?? []);
    if (array_key_exists('glueful/lemma', $deps)) {
        $violations[] = "{$name} depends on glueful/lemma (forbidden — use glueful/lemma-contracts)";
    }
}
// Source-level boundary: no first-party pack (except the contracts package) may reference App\*.
foreach (glob($root . '/packages/*', GLOB_ONLYDIR) ?: [] as $pkgDir) {
    if (basename($pkgDir) === 'lemma-contracts') {
        continue;
    }
    if (!is_dir($pkgDir . '/src')) {
        continue;
    }
    $srcIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pkgDir . '/src', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($srcIterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $src = (string) file_get_contents($file->getPathname());
        // Matches `use App\...`, `\App\...`, or a bare `App\` namespace reference.
        if (preg_match('/(^|[^\\w])App\\\\/m', $src) === 1) {
            $violations[] = basename($pkgDir) . '/src/' . $file->getFilename()
                . ' references App\\ (packs must use contracts, not the app)';
        }
    }
}
if ($violations !== []) {
    fwrite(STDERR, "Pack boundary violations:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}
echo "Pack boundaries OK (" . count(glob($root . '/packages/*/composer.json') ?: []) . " package(s) checked)\n";
exit(0);
