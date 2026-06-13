<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Content\Schema\ContentTypeSchema;
use App\Content\Validation\FieldValidator;
use App\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class FieldValidatorTest extends TestCase
{
    private function schema(): ContentTypeSchema
    {
        return ContentTypeSchema::fromArray([
            ['name' => 'title', 'type' => 'string', 'required' => true],
            ['name' => 'price', 'type' => 'number'],
            ['name' => 'status', 'type' => 'enum', 'enum' => ['draft', 'live']],
            ['name' => 'active', 'type' => 'boolean'],
        ]);
    }

    public function testAcceptsValidPayloadAndDropsUnknownKeys(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'Hi', 'price' => 9.5, 'status' => 'live', 'active' => true,
            'sneaky' => 'x', // unknown -> dropped, not an error
        ]);
        self::assertSame(['title' => 'Hi', 'price' => 9.5, 'status' => 'live', 'active' => true], $clean);
    }

    public function testRejectsMissingRequired(): void
    {
        try {
            (new FieldValidator())->validate($this->schema(), ['price' => 1]);
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('title', $e->errors());
        }
    }

    public function testRejectsWrongType(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['title' => 'ok', 'price' => 'not-a-number']);
    }

    public function testRejectsEnumOutsideSet(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), ['title' => 'ok', 'status' => 'archived']);
    }
}
