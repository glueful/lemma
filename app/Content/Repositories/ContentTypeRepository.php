<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Schema\ContentTypeSchema;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ContentTypeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): string
    {
        $uuid = Utils::generateNanoID(12);
        $schema = ContentTypeSchema::fromArray((array) ($data['schema'] ?? []));
        $this->db->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => (string) $data['slug'],
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'schema' => json_encode($schema->toArray(), JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_by' => isset($data['created_by']) ? (string) $data['created_by'] : null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        return $uuid;
    }

    /** @param list<array<string,mixed>> $schema */
    public function updateSchema(string $uuid, array $schema): void
    {
        $parsed = ContentTypeSchema::fromArray($schema);
        $current = $this->findByUuid($uuid);
        $this->db->table('content_types')->where('uuid', '=', $uuid)->update([
            'schema' => json_encode($parsed->toArray(), JSON_THROW_ON_ERROR),
            'schema_version' => (int) $current['schema_version'] + 1,
            'updated_at' => $this->now(),
        ]);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->hydrate($this->db->table('content_types')->where('uuid', '=', $uuid)->first());
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->hydrate($this->db->table('content_types')->where('slug', '=', $slug)->first());
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(
            fn(array $r): array => (array) $this->hydrate($r),
            $this->db->table('content_types')->orderBy('slug', 'ASC')->get()
        );
    }

    public function schemaFor(string $uuid): ContentTypeSchema
    {
        $row = $this->findByUuid($uuid);
        if ($row === null) {
            throw new \RuntimeException("content type {$uuid} not found");
        }
        return ContentTypeSchema::fromArray($row['schema']);
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['schema'] = is_string($row['schema'] ?? null)
            ? (json_decode((string) $row['schema'], true) ?? [])
            : (array) ($row['schema'] ?? []);
        $row['schema_version'] = (int) $row['schema_version'];
        return $row;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
