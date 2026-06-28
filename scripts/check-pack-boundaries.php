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
if ($violations !== []) {
    fwrite(STDERR, "Pack boundary violations:\n - " . implode("\n - ", $violations) . "\n");
    exit(1);
}
echo "Pack boundaries OK (" . count(glob($root . '/packages/*/composer.json') ?: []) . " package(s) checked)\n";
exit(0);
