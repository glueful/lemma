<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Query;

use DateTimeImmutable;
use Glueful\Database\Connection;

/**
 * Read service over the rollups. Reads analytics_daily for counts and analytics_active_actors for
 * distinct active users. Series are zero-filled so callers get a contiguous daily axis.
 */
final class AnalyticsQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{day: string, count: int}>
     */
    public function series(string $event, string $from, string $to, ?string $subject = null): array
    {
        $rows = $this->connection->table('analytics_daily')
            ->select(['day', 'count'])
            ->where('event', $event)
            ->where('subject', $subject ?? '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[substr((string) $row['day'], 0, 10)] = (int) $row['count'];
        }

        $out = [];
        $cursor = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $out[] = ['day' => $day, 'count' => $byDay[$day] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }
        return $out;
    }

    /**
     * @return array{from: string, to: string, totals: array<string, int>, active_users: int}
     */
    public function summary(string $from, string $to): array
    {
        $totals = [];
        $rows = $this->connection->table('analytics_daily')
            ->select(['event', 'count'])
            ->where('subject', '__total__')
            ->where('day', '>=', $from)
            ->where('day', '<=', $to)
            ->get();
        foreach ($rows as $row) {
            $event = (string) $row['event'];
            $totals[$event] = ($totals[$event] ?? 0) + (int) $row['count'];
        }

        // DISTINCT over the range — a user active on N days counts ONCE, not N (the per-(day,actor)
        // rows would otherwise be user-days). Raw SQL: the builder's count() is COUNT(*).
        $pdo = $this->connection->getPDO();
        $stmt = $pdo->prepare(
            'SELECT COUNT(DISTINCT actor_id_hash) FROM analytics_active_actors'
            . " WHERE metric = 'active_users' AND day >= ? AND day <= ?"
        );
        $stmt->execute([$from, $to]);
        $activeUsers = (int) $stmt->fetchColumn();

        return ['from' => $from, 'to' => $to, 'totals' => $totals, 'active_users' => $activeUsers];
    }
}
