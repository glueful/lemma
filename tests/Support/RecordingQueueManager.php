<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Glueful\Queue\QueueManager;

/**
 * A QueueManager stand-in that records every push() call instead of touching real queue
 * infrastructure. Subclasses the concrete manager (push() is public, non-final) and skips
 * the parent constructor, so no driver registry / plugins are initialised.
 *
 * Used by CapabilityGatingTest's present-env case: with a search seam bound, the
 * ReindexSearchListener enqueues a reindex job; this spy captures the job class + payload.
 */
final class RecordingQueueManager extends QueueManager
{
    /** @var list<array{job: string, data: array<string, mixed>}> */
    public array $pushed = [];

    // Intentionally no parent::__construct() — we never touch real drivers.
    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function push(string $job, array $data = [], ?string $queue = null, ?string $connection = null): string
    {
        $this->pushed[] = ['job' => $job, 'data' => $data];
        return 'recorded-' . count($this->pushed);
    }
}
