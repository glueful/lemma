<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\DTOs\Responses\HealthResultData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Services\HealthService;
use Glueful\Support\Version;

/**
 * Authenticated system-health report for the admin Utilities › Health page.
 *
 * Wraps the framework's {@see HealthService::getOverallHealth()} (database / cache / extensions /
 * config checks) and adds runtime/system info (version, PHP, memory, disk). The framework's public
 * `/health` routes are unauthenticated and minimal, and `/health/detailed` is behind a different
 * permission — this exposes the report under the admin's `system.access` gate. Read-only.
 */
final class HealthAdminController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/health */
    #[ApiOperation(
        summary: 'System health',
        description: 'Overall health (database, cache, extensions, config) plus runtime info '
            . '(version, PHP, memory, disk). Read-only. Requires `system.access`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: HealthResultData::class, description: 'Health report.')]
    public function show(): Response
    {
        $report = HealthService::getOverallHealth($this->context);

        $checks = [];
        /** @var array<string,mixed> $check */
        foreach ((array) ($report['checks'] ?? []) as $name => $check) {
            $checks[] = [
                'name' => (string) $name,
                'status' => is_array($check) ? (string) ($check['status'] ?? 'unknown') : 'unknown',
                'message' => is_array($check) ? (string) ($check['message'] ?? '') : '',
            ];
        }

        $root = base_path($this->context, '');

        return Response::success([
            'health' => [
                'status' => (string) ($report['status'] ?? 'unknown'),
                'version' => Version::getFullVersion(),
                'environment' => (string) ($report['environment'] ?? ''),
                'timestamp' => (string) ($report['timestamp'] ?? date('c')),
                'php_version' => PHP_VERSION,
                'memory_used' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_limit' => (string) ini_get('memory_limit'),
                'disk_free' => (int) @disk_free_space($root),
                'disk_total' => (int) @disk_total_space($root),
                'checks' => $checks,
            ],
        ], 'Health retrieved.');
    }
}
