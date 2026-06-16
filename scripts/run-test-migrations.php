<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Framework;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
if (is_file($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$envValue = static function (string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? getenv($key);

    return $value === false || $value === null ? $default : (string) $value;
};

$fail = static function (string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if ($envValue('APP_ENV', 'development') !== 'testing') {
    $fail('Refusing to run test migrations outside APP_ENV=testing.');
}

$database = $envValue('DB_PGSQL_DATABASE', '');
if ($database !== 'lemma_test' && !str_ends_with((string) $database, '_test')) {
    $fail("Refusing to run test migrations against non-test database '{$database}'.");
}

$context = Framework::create($root)
    ->withConfigDir($root . '/config')
    ->withEnvironment('testing')
    ->boot()
    ->getContext();

/** @var MigrationManager $manager */
$manager = $context->getContainer()->get(MigrationManager::class);
$pending = $manager->getPendingMigrations();

if ($pending === []) {
    fwrite(STDOUT, "No pending migrations found.\n");
} else {
    fwrite(STDOUT, sprintf("Found %d pending migration(s)\n", count($pending)));
    foreach ($pending as $file) {
        fwrite(STDOUT, ' - ' . basename($file) . PHP_EOL);
    }

    $result = $manager->migrate($pending);
    foreach ($result['applied'] as $file) {
        fwrite(STDOUT, 'Applied: ' . $file . PHP_EOL);
    }
    foreach ($result['failed'] as $file) {
        fwrite(STDERR, 'Failed: ' . $file . PHP_EOL);
    }

    if ($result['failed'] !== []) {
        $fail('Test migrations failed.');
    }
}

$connection = $context->getContainer()->get(Glueful\Database\Connection::class);
$schema = $connection->getSchemaBuilder();
$requiredTables = [
    'users',
    'roles',
    'permissions',
    'blobs',
    'import_export_jobs',
    'import_export_reports',
    'i18n_locales',
    'content_types',
    'entries',
];

$missing = [];
foreach ($requiredTables as $table) {
    if (!$schema->hasTable($table)) {
        $missing[] = $table;
    }
}

if ($missing !== []) {
    $fail('Missing required test tables after migrations: ' . implode(', ', $missing));
}

fwrite(STDOUT, "Test database schema verified.\n");
