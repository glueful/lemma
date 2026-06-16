<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Tests run sequentially in one process; the connection pool only adds the
// acquire-contention that deadlocks the CLI bootstraps. Force it off where the
// framework reads it ($_ENV, via env()), before .env loads so createImmutable
// keeps this value.
$_ENV['DB_POOLING_ENABLED'] = 'false';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
}
