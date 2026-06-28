<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Indexing;

use App\Content\Indexing\FieldSqlExpression;
use PHPUnit\Framework\TestCase;

final class MembershipArrayExpressionTest extends TestCase
{
    public function testMembershipArrayNormalizesToJsonbArray(): void
    {
        $sql = FieldSqlExpression::membershipArray('category');
        self::assertStringContainsString("fields -> 'category' IS NULL", $sql);
        self::assertStringContainsString("jsonb_typeof(fields -> 'category') = 'array'", $sql);
        self::assertStringContainsString("jsonb_build_array(fields -> 'category')", $sql);
        self::assertStringContainsString("'[]'::jsonb", $sql);
    }
}
