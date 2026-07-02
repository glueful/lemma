<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Lemma\Analytics\Query\AnalyticsQuery;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;

use function config;

/**
 * Admin read API over the analytics rollups. Gated by analytics.read in the route definitions.
 */
final class AnalyticsController
{
    public function __construct(
        private readonly AnalyticsQuery $query,
        private readonly ApplicationContext $context,
    ) {
    }

    #[ApiOperation(summary: 'Analytics time-series for one metric', tags: ['Analytics'])]
    public function series(Request $request): Response
    {
        $metric = (string) $request->query->get('metric', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($metric === '' || $from === '' || $to === '') {
            return Response::error('metric, from and to are required.', 422);
        }
        if (($error = $this->validateRange($from, $to)) !== null) {
            return $error;
        }
        $subject = $request->query->get('dimension') === 'subject'
            ? (string) $request->query->get('subject', '')
            : null;
        $subject = ($subject === '' ? null : $subject);

        return Response::success([
            'metric' => $metric,
            'from' => $from,
            'to' => $to,
            'series' => $this->query->series($metric, $from, $to, $subject),
        ]);
    }

    #[ApiOperation(summary: 'Analytics summary (KPIs incl. active users)', tags: ['Analytics'])]
    public function summary(Request $request): Response
    {
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($from === '' || $to === '') {
            return Response::error('from and to are required.', 422);
        }
        if (($error = $this->validateRange($from, $to)) !== null) {
            return $error;
        }
        return Response::success($this->query->summary($from, $to));
    }

    #[ApiOperation(summary: 'Analytics breakdown: top subjects for one event', tags: ['Analytics'])]
    public function breakdown(Request $request): Response
    {
        $event = (string) $request->query->get('event', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');
        if ($event === '' || $from === '' || $to === '') {
            return Response::error('event, from and to are required.', 422);
        }
        if (($error = $this->validateRange($from, $to)) !== null) {
            return $error;
        }
        $limit = (int) $request->query->get('limit', 10);

        return Response::success([
            'event' => $event,
            'from' => $from,
            'to' => $to,
            'breakdown' => $this->query->breakdown($event, $from, $to, $limit),
        ]);
    }

    /**
     * Reject malformed or abusive date ranges with a 422 (returns null when the range is valid).
     * Without this, an unparseable date reaches series()'s `new DateTimeImmutable($from)` as an
     * uncaught 500, and an enormous span makes its per-day zero-fill loop run for millions of
     * iterations.
     */
    private function validateRange(string $from, string $to): ?Response
    {
        try {
            $fromDate = new \DateTimeImmutable($from);
            $toDate = new \DateTimeImmutable($to);
        } catch (\Exception) {
            return Response::error('from and to must be valid dates.', 422);
        }
        if ($fromDate > $toDate) {
            return Response::error('from must be on or before to.', 422);
        }
        $maxDays = (int) config($this->context, 'analytics.max_range_days', 366);
        if ($maxDays > 0 && $fromDate->diff($toDate)->days > $maxDays) {
            return Response::error(sprintf('Date range too large (max %d days).', $maxDays), 422);
        }
        return null;
    }
}
