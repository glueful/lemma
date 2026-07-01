<?php

declare(strict_types=1);

namespace Glueful\Lemma\Analytics\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Lemma\Analytics\Query\AnalyticsQuery;
use Glueful\Routing\Attributes\ApiOperation;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin read API over the analytics rollups. Gated by analytics.read in the route definitions.
 */
final class AnalyticsController
{
    public function __construct(private readonly AnalyticsQuery $query)
    {
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
        return Response::success($this->query->summary($from, $to));
    }
}
