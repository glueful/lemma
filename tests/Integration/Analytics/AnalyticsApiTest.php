<?php

declare(strict_types=1);

namespace App\Tests\Integration\Analytics;

use App\Tests\Support\LemmaTestCase;
use Glueful\Lemma\Analytics\Facts\AnalyticsFact;
use Glueful\Lemma\Analytics\Facts\AnalyticsRecorder;
use Glueful\Lemma\Analytics\Http\Controllers\AnalyticsController;
use Symfony\Component\HttpFoundation\Request;

final class AnalyticsApiTest extends LemmaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = $this->connection()->getPDO();
        $pdo->exec('DELETE FROM analytics_facts');
        $pdo->exec('DELETE FROM analytics_daily');
        $pdo->exec('DELETE FROM analytics_active_actors');
    }

    public function testSeriesEndpointReturnsZeroFilledData(): void
    {
        $this->container()->get(AnalyticsRecorder::class)->record(new AnalyticsFact(
            event: 'collections.row.created',
            category: 'collections',
            subjectType: 'collection',
            subjectId: 'posts',
            actorType: 'user',
            actorId: 'u-1',
            occurredAt: 1749556800.0,
        ));

        $controller = $this->container()->get(AnalyticsController::class);
        $req = Request::create('/v1/admin/analytics/series', 'GET', [
            'metric' => 'collections.row.created', 'from' => '2025-06-10', 'to' => '2025-06-10',
        ]);
        $res = $controller->series($req);

        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getContent(), true);
        self::assertSame([['day' => '2025-06-10', 'count' => 1]], $body['data']['series']);
    }
}
