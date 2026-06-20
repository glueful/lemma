<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Content\Http\Controllers\AdminConfigController;
use App\Content\Http\Controllers\SetupController;
use App\Content\Http\DTOs\Requests\SetupData;
use App\Setup\SetupService;
use App\Tests\Support\LemmaTestCase;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Validation\RequestDataHydrator;

/**
 * Integration tests for `POST /admin/setup`.
 *
 * Verifies:
 * - First setup creates the admin user and flips the installed marker.
 * - Subsequent requests are permanently locked with 409.
 * - config.json reports installed:false before and installed:true after.
 * - The gate reads the persisted installed invariant, not controller-local state.
 *
 * Requires `composer test:migrate` to have been run first (lemma_settings + users tables must exist).
 */
final class SetupApiTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Start from a clean slate on each test: wipe users, Aegis user_roles, and the
        // lemma_settings markers so install() is always re-runnable.
        $this->connection()->getPDO()->exec('TRUNCATE TABLE users, user_roles, lemma_settings CASCADE');
    }

    private function service(): SetupService
    {
        return $this->container()->get(SetupService::class);
    }

    private function controller(): SetupController
    {
        return new SetupController($this->appContext(), $this->service());
    }

    /** @param array<string,mixed> $body */
    private function setupData(array $body): SetupData
    {
        /** @var SetupData $dto */
        $dto = (new RequestDataHydrator())->hydrate(SetupData::class, $body);
        return $dto;
    }

    /** @return array<string,string> */
    private function validBody(): array
    {
        return [
            'site_name'      => 'getlemma.dev',
            'admin_email'    => 'admin@getlemma.dev',
            'admin_password' => 'correct horse battery',
            'locale'         => 'en',
        ];
    }

    public function testFirstSetupCreatesAdminAndFlipsInstalled(): void
    {
        self::assertFalse($this->service()->isInstalled(), 'a fresh install is not installed');

        $resp = $this->controller()->setup($this->setupData($this->validBody()));

        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue($this->service()->isInstalled(), 'install() flips the installed marker');

        // The admin now exists and can be looked up by email (created via glueful/users).
        $userRepo = $this->container()->get(UserRepository::class);
        self::assertNotNull(
            $userRepo->findByEmail('admin@getlemma.dev'),
            'the first admin was created',
        );
    }

    public function testSecondSetupIsPermanentlyLockedWith409(): void
    {
        $this->controller()->setup($this->setupData($this->validBody()));

        $second = $this->controller()->setup($this->setupData([
            'site_name'      => 'evil.example',
            'admin_email'    => 'attacker@evil.example',
            'admin_password' => 'another password',
            'locale'         => 'en',
        ]));

        self::assertSame(409, $second->getStatusCode(), 'setup self-locks once installed');

        $userRepo = $this->container()->get(UserRepository::class);
        self::assertNull(
            $userRepo->findByEmail('attacker@evil.example'),
            'no second admin is ever created',
        );
    }

    public function testConfigJsonReportsInstalledBeforeAndAfter(): void
    {
        $config = new AdminConfigController($this->appContext(), $this->service());

        $before = json_decode((string) $config->config()->getContent(), true);
        self::assertFalse($before['installed'], 'config.json reports installed:false before setup');

        $this->controller()->setup($this->setupData($this->validBody()));

        $after = json_decode((string) $config->config()->getContent(), true);
        self::assertTrue($after['installed'], 'config.json reports installed:true after setup');
    }

    public function testGateIsBoundToTheInstalledInvariantNotASoftCheck(): void
    {
        // Guard test: the lock is the installed/no-admin INVARIANT, not a one-shot flag the
        // controller flips. Install via the service directly (bypassing the controller), then a
        // controller setup must STILL be refused — proving the gate reads isInstalled(), which is
        // backed by the persisted marker / first-admin uniqueness, not controller-local state.
        $this->service()->install('seeded.example', 'seed@example.test', 'seed password', 'en');

        $resp = $this->controller()->setup($this->setupData($this->validBody()));
        self::assertSame(409, $resp->getStatusCode());
    }

    public function testSetupRouteIsRegisteredUnauthenticated(): void
    {
        $route = $this->findRoute('POST', '/admin/setup');
        self::assertNotNull($route, 'POST /admin/setup must be registered');
        self::assertNotContains('auth', (array) ($route['middleware'] ?? []), 'setup must be unauthenticated');
    }
}
