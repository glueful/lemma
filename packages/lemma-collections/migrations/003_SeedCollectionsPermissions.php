<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Seeds the collections admin permissions and grants them to the existing `administrator` role.
 *
 * The `collections.*` permission slugs exist only because this pack exists, so the pack declares
 * them. Runs as a DEPENDENT migration (after Aegis seeds the role ladder), so `administrator`
 * already exists when we grant onto it.
 *
 * Rollback is additive-safe: `down()` is a NO-OP. Disabling/removing the pack must preserve data and
 * avoid surprising RBAC churn, so the permission rows + grants are never stripped on rollback.
 */
final class SeedCollectionsPermissions implements MigrationInterface
{
    /** slug => label. Owned by lemma-collections. */
    private const PERMISSIONS = [
        'collections.manage' => 'Manage data collections',
        'collections.schema.manage' => 'Create, alter and drop collection structure and indexes',
        'collections.data.manage' => 'Create, edit and delete collection rows via the admin',
    ];

    /** The existing Aegis role these are granted onto. */
    private const ADMIN_ROLE = 'administrator';

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        $permUuids = $this->ensureRows('permissions', array_map(
            static fn (string $slug, string $label): array => [
                'slug' => $slug, 'name' => $label, 'category' => 'collections',
                'description' => $label, 'is_system' => true,
            ],
            array_keys(self::PERMISSIONS),
            array_values(self::PERMISSIONS),
        ));

        $roleUuid = $this->lookupUuids('roles', [self::ADMIN_ROLE])[self::ADMIN_ROLE] ?? null;
        if ($roleUuid === null) {
            return; // administrator not seeded (unexpected): permissions exist, grant is skipped.
        }

        $this->assign($roleUuid, array_keys(self::PERMISSIONS), $permUuids);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Intentional no-op (additive-safe): removing the pack must not strip granted permissions or
        // churn RBAC. The collections.* permission rows and grants are left in place.
    }

    public function getDescription(): string
    {
        return 'Seed collections.* admin permissions and grant them to the administrator role.';
    }

    /**
     * Insert rows that don't already exist (matched by slug); return slug => uuid for all.
     *
     * @param list<array<string,mixed>> $rows each carries a 'slug'
     * @return array<string,string>
     */
    private function ensureRows(string $table, array $rows): array
    {
        $bySlug = $this->lookupUuids($table, array_column($rows, 'slug'));
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

    /**
     * Look up existing rows by slug (never creates). Returns slug => uuid.
     *
     * @param list<string> $slugs
     * @return array<string,string>
     */
    private function lookupUuids(string $table, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table($table)->select(['uuid', 'slug'])->whereIn('slug', $slugs)->get() as $r) {
            $out[$r['slug']] = $r['uuid'];
        }
        return $out;
    }

    /**
     * Idempotently grant the permissions to one role (skips pairs that already exist).
     *
     * @param list<string>         $permSlugs
     * @param array<string,string> $permUuids slug => uuid
     */
    private function assign(string $roleUuid, array $permSlugs, array $permUuids): void
    {
        $existing = [];
        foreach (
            $this->db->table('role_permissions')->select(['permission_uuid'])
                ->where('role_uuid', $roleUuid)->get() as $row
        ) {
            $existing[$row['permission_uuid']] = true;
        }
        $new = [];
        foreach ($permSlugs as $slug) {
            $permUuid = $permUuids[$slug] ?? null;
            if ($permUuid === null || isset($existing[$permUuid])) {
                continue;
            }
            $new[] = ['uuid' => Utils::generateNanoID(), 'role_uuid' => $roleUuid, 'permission_uuid' => $permUuid];
            $existing[$permUuid] = true;
        }
        if ($new !== []) {
            $this->db->table('role_permissions')->insertBatch($new);
        }
    }
}
