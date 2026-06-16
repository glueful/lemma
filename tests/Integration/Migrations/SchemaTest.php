<?php

declare(strict_types=1);

namespace App\Tests\Integration\Migrations;

use App\Tests\Support\LemmaTestCase;

final class SchemaTest extends LemmaTestCase
{
    /** @dataProvider tables */
    public function testTableExists(string $table): void
    {
        self::assertTrue(
            $this->connection()->getSchemaBuilder()->hasTable($table),
            "expected table {$table} to exist after migration"
        );
    }

    /** @return array<int, array{0:string}> */
    public static function tables(): array
    {
        return array_map(
            static fn(string $t): array => [$t],
            [
                'content_types', 'entries', 'entry_drafts', 'entry_versions',
                'entry_publications', 'entry_routes', 'entry_references',
            ]
        );
    }

    public function testFieldsColumnIsJsonb(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'entry_versions')
            ->where('column_name', '=', 'fields')
            ->first();
        self::assertSame('jsonb', $col['data_type']);
    }

    public function testContentTypesCarryOptionalDeliveryCacheTtl(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'content_types')
            ->where('column_name', '=', 'cache_ttl')
            ->first();

        self::assertNotNull($col, 'content_types.cache_ttl stores per-type delivery max-age overrides');
        self::assertSame('integer', $col['data_type']);
        self::assertSame('YES', $col['is_nullable']);
    }

    public function testContentTypesCarryPublicDeliveryOptIn(): void
    {
        $col = $this->connection()->table('information_schema.columns')
            ->where('table_name', '=', 'content_types')
            ->where('column_name', '=', 'public_delivery')
            ->first();

        self::assertNotNull($col, 'content_types.public_delivery opts a type into anonymous delivery reads');
        self::assertSame('boolean', $col['data_type']);
    }
}
