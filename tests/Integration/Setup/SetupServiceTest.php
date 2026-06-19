<?php

declare(strict_types=1);

namespace App\Tests\Integration\Setup;

use App\Setup\SetupService;
use App\Tests\Support\LemmaTestCase;

/**
 * Verifies the SetupService install flow end-to-end against a real PostgreSQL database.
 *
 * Requires `composer test:migrate` to have run first (lemma_settings table must exist).
 */
final class SetupServiceTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Start each test from a clean slate. The users table is uuid-keyed (no `id`
        // column), so TRUNCATE ... CASCADE is the reliable wipe — it clears users, the
        // Aegis user_roles child rows, and the lemma_settings markers regardless of PK.
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles, lemma_settings CASCADE');
    }

    private function service(): SetupService
    {
        return $this->container()->get(SetupService::class);
    }

    public function testIsInstalledReturnsFalseOnFreshInstall(): void
    {
        self::assertFalse($this->service()->isInstalled());
    }

    public function testInstallCreatesAdminAndSetsInstalledMarker(): void
    {
        $svc = $this->service();

        $svc->install(
            siteName: 'Lemma Test Site',
            adminEmail: 'admin@example.com',
            adminPassword: 'S3cur3P@ssw0rd!',
            locale: 'en',
        );

        self::assertTrue($svc->isInstalled());

        // Verify the admin password is stored as a hash, never as plaintext.
        $userRow = $this->connection()->table('users')
            ->where(['email' => 'admin@example.com'])
            ->first();

        self::assertNotNull($userRow, 'admin user must exist after install');
        self::assertNotSame('S3cur3P@ssw0rd!', $userRow['password'], 'password must not be stored plaintext');
        self::assertTrue(
            password_verify('S3cur3P@ssw0rd!', (string) $userRow['password']),
            'stored hash must verify against the original password',
        );

        // Verify site_name was written to lemma_settings.
        $row = $this->connection()->table('lemma_settings')
            ->where(['key' => 'site_name'])
            ->first();

        self::assertNotNull($row);
        self::assertSame('Lemma Test Site', $row['value']);

        // Verify default_locale was written.
        $localeRow = $this->connection()->table('lemma_settings')
            ->where(['key' => 'default_locale'])
            ->first();

        self::assertNotNull($localeRow);
        self::assertSame('en', $localeRow['value']);
    }

    public function testInstallIsPermanentLock(): void
    {
        $svc = $this->service();

        $svc->install(
            siteName: 'First Install',
            adminEmail: 'admin2@example.com',
            adminPassword: 'S3cur3P@ssw0rd!',
            locale: 'en',
        );

        self::assertTrue($svc->isInstalled());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lemma is already installed.');

        $svc->install(
            siteName: 'Second Attempt',
            adminEmail: 'other@example.com',
            adminPassword: 'AnotherPass!',
            locale: 'fr',
        );
    }
}
