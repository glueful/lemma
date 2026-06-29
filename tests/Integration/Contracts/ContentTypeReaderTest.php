<?php

declare(strict_types=1);

namespace App\Tests\Integration\Contracts;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Contracts\Schema\ContentSchemaReader;
use Glueful\Lemma\Contracts\Schema\ContentTypeReader;

final class ContentTypeReaderTest extends LemmaTestCase
{
    public function testResolvesBySlugAndReadsSchema(): void
    {
        $this->connection()->table('content_types')->insert([
            'uuid' => 'type00000001',
            'slug' => 'post',
            'name' => 'Post',
            'schema' => json_encode([
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'text', 'format' => 'rich'],
            ]),
            'schema_version' => 1,
        ]);

        $reader = $this->container()->get(ContentTypeReader::class);
        self::assertInstanceOf(ContentTypeReader::class, $reader);

        self::assertSame('type00000001', $reader->findUuidBySlug('post'));
        self::assertNull($reader->findUuidBySlug('nope'));

        $schema = $reader->schemaFor('type00000001');
        self::assertInstanceOf(ContentSchemaReader::class, $schema);
        self::assertNotNull($schema->field('title'));
        // FieldDescriptor::format() is exposed for the importers' raw-vs-HTML body decision.
        self::assertSame('rich', $schema->field('body')?->format());
        self::assertNull($schema->field('title')?->format()); // non-text field has no format
        self::assertNull($reader->schemaFor('missing'));
    }
}
