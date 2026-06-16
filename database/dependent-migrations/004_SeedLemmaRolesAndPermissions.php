<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

final class SeedLemmaRolesAndPermissions implements MigrationInterface
{
    /** permission slug => human label */
    private const PERMISSIONS = [
        'lemma.models.manage' => 'Manage content models',
        'lemma.entries.write' => 'Create and edit entries',
        'lemma.entries.publish' => 'Publish and unpublish entries',
        'lemma.entries.read' => 'Read entries (admin)',
    ];

    /** role slug => [name, level, granted permission slugs] */
    private const ROLES = [
        'lemma_admin' => ['Lemma Admin', 80, [
            'lemma.models.manage', 'lemma.entries.write', 'lemma.entries.publish', 'lemma.entries.read',
        ]],
        'lemma_editor' => ['Lemma Editor', 50, [
            'lemma.entries.write', 'lemma.entries.publish', 'lemma.entries.read',
        ]],
        'lemma_viewer' => ['Lemma Viewer', 20, ['lemma.entries.read']],
    ];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        $roleUuids = $this->ensureRows(
            'roles',
            array_map(static fn(string $slug, array $r): array => [
                'slug' => $slug, 'name' => $r[0], 'description' => $r[0],
                'level' => $r[1], 'is_system' => true, 'status' => 'active',
            ], array_keys(self::ROLES), self::ROLES)
        );

        $permUuids = $this->ensureRows(
            'permissions',
            array_map(static fn(string $slug, string $label): array => [
                'slug' => $slug, 'name' => $label, 'category' => 'lemma',
                'description' => $label, 'is_system' => true,
            ], array_keys(self::PERMISSIONS), self::PERMISSIONS)
        );

        // role_permissions assignments (idempotent on the (role_uuid, permission_uuid) pair).
        $existing = [];
        foreach ($this->db->table('role_permissions')->select(['role_uuid', 'permission_uuid'])->get() as $row) {
            $existing[$row['role_uuid'] . '|' . $row['permission_uuid']] = true;
        }
        $newAssignments = [];
        foreach (self::ROLES as $roleSlug => [, , $grants]) {
            foreach ($grants as $permSlug) {
                $pair = $roleUuids[$roleSlug] . '|' . $permUuids[$permSlug];
                if (!isset($existing[$pair])) {
                    $newAssignments[] = [
                        'uuid' => Utils::generateNanoID(),
                        'role_uuid' => $roleUuids[$roleSlug],
                        'permission_uuid' => $permUuids[$permSlug],
                    ];
                }
            }
        }
        if ($newAssignments !== []) {
            $this->db->table('role_permissions')->insertBatch($newAssignments);
        }
    }

    /**
     * Insert rows that don't already exist (matched by slug); return slug => uuid for all.
     *
     * @param list<array<string,mixed>> $rows each carries a 'slug'
     * @return array<string,string>
     */
    private function ensureRows(string $table, array $rows): array
    {
        $slugs = array_column($rows, 'slug');
        $bySlug = [];
        foreach ($this->db->table($table)->select(['uuid', 'slug'])->whereIn('slug', $slugs)->get() as $r) {
            $bySlug[$r['slug']] = $r['uuid'];
        }
        $insert = [];
        foreach ($rows as $row) {
            if (!isset($bySlug[$row['slug']])) {
                $row['uuid'] = Utils::generateNanoID();
                $bySlug[$row['slug']] = $row['uuid'];
                $insert[] = $row;
            }
        }
        if ($insert !== []) {
            $this->db->table($table)->insertBatch($insert);
        }
        return $bySlug;
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permUuids = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid'
        );
        if ($permUuids !== []) {
            $this->db->table('role_permissions')->whereIn('permission_uuid', $permUuids)->delete();
        }
        $this->db->table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();
        $this->db->table('roles')->whereIn('slug', array_keys(self::ROLES))->delete();
    }

    public function getDescription(): string
    {
        return 'Seed Lemma roles (admin/editor/viewer) and namespaced permissions into aegis.';
    }
}
