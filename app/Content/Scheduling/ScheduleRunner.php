<?php

declare(strict_types=1);

namespace App\Content\Scheduling;

use App\Content\Enums\ScheduleAction;
use App\Content\Enums\ScheduleStatus;
use App\Content\Repositories\EntryRepository;
use App\Content\Repositories\ScheduleRepository;
use App\Content\Services\PublishService;
use App\Settings\GeneralSettings;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Fires due scheduled publish/unpublish actions through the normal publish path.
 *
 * The durable claim and terminal outcome writes are separate from the action itself:
 * claimDuePending() commits pending -> processing first, PublishService owns its own
 * transaction, and markOutcome() writes done/failed/canceled afterwards.
 */
final class ScheduleRunner
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ScheduleRepository $schedules,
        private readonly PublishService $publisher,
        private readonly EntryRepository $entries,
    ) {
    }

    public function run(int $limit = 100): int
    {
        if (!app($this->context, GeneralSettings::class)->schedulerEnabled()) {
            return 0;
        }

        $this->schedules->reclaimStale(300);

        // Per-run lease token: rows this run claims are stamped with it, and only this run can write
        // their terminal outcome. If a slow batch overruns the reclaim window and another run takes
        // a row over, our markOutcome for it no-ops instead of racing that run's result.
        $lockToken = bin2hex(random_bytes(16));

        $fired = 0;
        foreach ($this->schedules->claimDuePending($limit, $lockToken) as $row) {
            [$status, $reason] = $this->fire($row);
            $this->schedules->markOutcome((int) $row['id'], $status, $reason, $lockToken);
            $fired++;
        }

        return $fired;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:ScheduleStatus,1:?string}
     */
    private function fire(array $row): array
    {
        $entry = $this->entries->findEntry((string) $row['entry_uuid']);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return [ScheduleStatus::Canceled, 'target entry no longer exists'];
        }

        try {
            if ($row['action'] === ScheduleAction::Publish->value) {
                $actor = ((string) ($row['created_by'] ?? '')) ?: null;
                $this->publisher->publish((string) $row['entry_uuid'], (string) $row['locale'], $actor);
            } else {
                $this->publisher->unpublish((string) $row['entry_uuid'], (string) $row['locale']);
            }

            return [ScheduleStatus::Done, null];
        } catch (\Throwable $e) {
            return [ScheduleStatus::Failed, $e->getMessage()];
        }
    }
}
