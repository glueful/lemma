<?php

declare(strict_types=1);

namespace App\Tests\Integration\Content;

use App\Content\Repositories\RouteRepository;
use App\Tests\Support\LemmaTestCase;

final class RouteRepositoryTest extends LemmaTestCase
{
    private function repo(): RouteRepository
    {
        return new RouteRepository($this->connection());
    }

    public function testAssignThenLookup(): void
    {
        $this->repo()->assign('entry0000001', 'type0000001', 'en', 'hello');
        $row = $this->repo()->findBySlug('type0000001', 'en', 'hello');
        self::assertSame('entry0000001', $row['entry_uuid']);
    }

    public function testDuplicateSlugInSameTypeLocaleRejected(): void
    {
        $this->repo()->assign('entry0000001', 'type0000001', 'en', 'hello');
        self::assertFalse($this->repo()->isSlugAvailable('type0000001', 'en', 'hello'));
        self::assertTrue($this->repo()->isSlugAvailable('type0000001', 'fr', 'hello'));
    }
}
