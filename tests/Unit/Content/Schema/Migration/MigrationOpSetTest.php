<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content\Schema\Migration;

use App\Content\Schema\Migration\DeleteField;
use App\Content\Schema\Migration\MigrationCollisionException;
use App\Content\Schema\Migration\MigrationOpSet;
use App\Content\Schema\Migration\RenameField;
use PHPUnit\Framework\TestCase;

final class MigrationOpSetTest extends TestCase
{
    public function testRenameAndDeleteTransformFieldsInOrder(): void
    {
        $ops = new MigrationOpSet([
            new RenameField('title', 'heading'),
            new DeleteField('legacy'),
        ]);

        self::assertSame(
            ['body' => 'Copy', 'heading' => 'Hello'],
            $ops->apply(['title' => 'Hello', 'body' => 'Copy', 'legacy' => true])
        );
    }

    public function testRenameThrowsOnMaterializeCollision(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);

        $this->expectException(MigrationCollisionException::class);

        $ops->apply(['title' => 'Hello', 'heading' => 'Existing']);
    }

    public function testProjectionKeepsExistingTargetAndDropsOldSource(): void
    {
        $ops = new MigrationOpSet([new RenameField('title', 'heading')]);

        self::assertSame(
            ['heading' => 'Existing'],
            $ops->applyForProjection(['title' => 'Hello', 'heading' => 'Existing'])
        );
    }

    public function testSerializesAndRehydratesOps(): void
    {
        $ops = new MigrationOpSet([
            new RenameField('title', 'heading'),
            new DeleteField('legacy'),
        ]);

        $rehydrated = MigrationOpSet::fromArray($ops->toArray());

        self::assertSame(
            ['heading' => 'Hello'],
            $rehydrated->apply(['title' => 'Hello', 'legacy' => true])
        );
    }

    public function testUnknownSerializedOpFailsLoud(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigrationOpSet::fromArray([['op' => 'retype', 'name' => 'title']]);
    }
}
