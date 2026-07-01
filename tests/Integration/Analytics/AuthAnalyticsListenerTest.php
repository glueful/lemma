<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Events\Auth\AuthenticationFailedEvent;
use Glueful\Events\Auth\SessionCreatedEvent;
use Glueful\Events\Auth\SessionDestroyedEvent;
use Glueful\Events\EventService;

final class AuthAnalyticsListenerTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM analytics_facts');
        $pdo->exec('DELETE FROM analytics_daily');
        $pdo->exec('DELETE FROM analytics_active_actors');
    }

    public function testLoginRecordsFactAndActiveUserWithNoTokenMaterial(): void
    {
        $events = $this->container()->get(EventService::class);
        // Real constructor: SessionCreatedEvent(array $sessionData, array $tokens, array $metadata = []).
        // getUserUuid() reads $sessionData['uuid'].
        $events->dispatch(new SessionCreatedEvent(
            ['uuid' => 'u-1', 'username' => 'maketech'],
            ['access_token' => 'ACCESS-TOKEN-SECRET'],
        ));

        $fact = $this->connection()->table('analytics_facts')->where('event', 'auth.login')->first();
        self::assertNotNull($fact);
        self::assertSame('u-1', $fact['actor_id']);
        self::assertNull($fact['metadata']); // no token, no PII
        $serialized = json_encode($fact);
        self::assertStringNotContainsString('ACCESS-TOKEN-SECRET', (string) $serialized);

        self::assertSame(1, (int) $this->connection()->table('analytics_active_actors')
            ->where('metric', 'active_users')->count());
    }

    public function testLoginFailedIsCountOnlyWithNoIdentity(): void
    {
        $events = $this->container()->get(EventService::class);
        $events->dispatch(new AuthenticationFailedEvent('victim@example.com', 'invalid_credentials', '203.0.113.7'));

        $fact = $this->connection()->table('analytics_facts')->where('event', 'auth.login_failed')->first();
        self::assertNotNull($fact);
        self::assertNull($fact['actor_id']);
        self::assertNull($fact['subject_id']); // attempted username NOT stored
        $serialized = json_encode($fact);
        self::assertStringNotContainsString('victim@example.com', (string) $serialized);
    }
}
