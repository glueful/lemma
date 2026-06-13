<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use PHPUnit\Framework\TestCase;

abstract class LemmaTestCase extends TestCase
{
    protected static ?ApplicationContext $app = null;

    // Truncate order is child -> parent (no FKs in v1, but keep it deterministic).
    private const TABLES = [
        'entry_references', 'entry_routes', 'entry_publications',
        'entry_versions', 'entry_drafts', 'entries', 'content_types',
    ];

    public static function setUpBeforeClass(): void
    {
        if (self::$app === null) {
            $root = dirname(__DIR__, 2);
            // Schema is created by `composer test:migrate` before PHPUnit runs.
            // Framework::boot() returns a Glueful\Application; we keep its
            // ApplicationContext (both expose getContainer()).
            self::$app = Framework::create($root)
                ->withConfigDir($root . '/config')
                ->withEnvironment('testing')
                ->boot()
                ->getContext();
        }
    }

    protected function setUp(): void
    {
        // QueryBuilder has no truncate(); delete-all via a tautological predicate
        // (every Lemma table has an integer `id`). Deletes commit immediately.
        foreach (self::TABLES as $t) {
            $this->connection()->table($t)->where('id', '>', 0)->delete();
        }
    }

    protected function appContext(): ApplicationContext
    {
        return self::$app;
    }

    protected function connection(): Connection
    {
        return self::$app->getContainer()->get(Connection::class);
    }
}
