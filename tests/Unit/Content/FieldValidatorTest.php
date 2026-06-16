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
            ['name' => 'published_at', 'type' => 'datetime'],
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

    public function testNormalizesSpaceSeparatedDatetimeToCanonicalUtc(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'ok',
            'published_at' => '2026-06-14 09:30:00',
        ]);
        // Local '2026-06-14 09:30:00' is interpreted in the default TZ and emitted as UTC `...Z`.
        self::assertSame(
            FieldValidator::normalizeDatetime('2026-06-14 09:30:00'),
            $clean['published_at']
        );
        self::assertMatchesRegularExpression(
            '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/',
            $clean['published_at']
        );
    }

    public function testNormalizesOffsetDatetimeToUtc(): void
    {
        $clean = (new FieldValidator())->validate($this->schema(), [
            'title' => 'ok',
            'published_at' => '2026-06-14T09:30:00+02:00',
        ]);
        // +02:00 09:30 == 07:30 UTC
        self::assertSame('2026-06-14T07:30:00Z', $clean['published_at']);
    }

    public function testRejectsUnparseableDatetime(): void
    {
        $this->expectException(ValidationException::class);
        (new FieldValidator())->validate($this->schema(), [
            'title' => 'ok',
            'published_at' => 'not-a-date',
        ]);
    }
}
