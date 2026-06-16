<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Mirror the process env into $_ENV (the framework's env() reads $_ENV only; CI's
// variables_order / Dotenv immutable-skip can leave it empty), then force pooling off.
// Before the .env load so createImmutable keeps these values.
foreach (getenv() as $key => $value) {
    $_ENV[$key] ??= $value;
}
$_ENV['DB_POOLING_ENABLED'] = 'false';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
}
