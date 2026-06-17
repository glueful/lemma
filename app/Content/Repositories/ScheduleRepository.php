<?php

declare(strict_types=1);

namespace App\Content\Repositories;

use App\Content\Enums\ScheduleAction;
use App\Content\Enums\ScheduleStatus;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ScheduleRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function schedule(
        string $entryUuid,
        string $locale,
        ScheduleAction $action,
        string $runAtUtc,
        ?string $actor,
    ): array {
        $existing = $this->db->table('entry_schedules')
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->where('action', '=', $action->value)
            ->where('status', '=', ScheduleStatus::Pending->value)
            ->first();

        if ($existing !== null) {
            $this->db->table('entry_schedules')
                ->where('id', '=', $existing['id'])
                ->update([
                    'run_at' => $runAtUtc,
                    'created_by' => $actor,
                    'updated_at' => $this->now(),
                ]);
            $row = (array) $this->find((string) $existing['uuid']);
            $row['replaced'] = true;

            return $row;
        }

        $uuid = Utils::generateNanoID(12);
        $this->db->table('entry_schedules')->insert([
            'uuid' => $uuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'action' => $action->value,
            'run_at' => $runAtUtc,
            'status' => ScheduleStatus::Pending->value,
            'attempts' => 0,
            'created_by' => $actor,
            'created_at' => $this->now(),
        ]);

        $row = (array) $this->find($uuid);
        $row['replaced'] = false;

        return $row;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function forEntry(string $entryUuid): array
    {
        return $this->normalizeRows($this->db->table('entry_schedules')
            ->where('entry_uuid', '=', $entryUuid)
            ->orderBy('id', 'DESC')
            ->get());
    }

    public function cancel(string $entryUuid, string $scheduleUuid, ?string $actor): bool
    {
        $affected = $this->db->table('entry_schedules')
            ->where('uuid', '=', $scheduleUuid)
            ->where('entry_uuid', '=', $entryUuid)
            ->where('status', '=', ScheduleStatus::Pending->value)
            ->update([
                'status' => ScheduleStatus::Canceled->value,
                'canceled_at' => $this->now(),
                'canceled_by' => $actor,
                'updated_at' => $this->now(),
            ]);

        return $affected >= 1;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(string $scheduleUuid): ?array
    {
        $row = $this->db->table('entry_schedules')->where('uuid', '=', $scheduleUuid)->first();

        return $row === null ? null : $this->normalizeRow($row);
    }

    public function normalizeRunAt(string $input): string
    {
        if (preg_match('/(?:Z|[+-]\d{2}:?\d{2})$/', $input) !== 1) {
            throw new \InvalidArgumentException('run_at must include an explicit timezone.');
        }

        try {
            $date = new \DateTimeImmutable($input);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('run_at must be a valid ISO-8601 timestamp.', previous: $e);
        }

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function claimDuePending(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $pdo = $this->db->getPDO();
        $pdo->beginTransaction();

        try {
            $select = $pdo->prepare(
                "SELECT id
                 FROM entry_schedules
                 WHERE status = 'pending' AND run_at <= now()
                 ORDER BY run_at ASC
                 LIMIT :limit
                 FOR UPDATE SKIP LOCKED"
            );
            $select->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $select->execute();

            $ids = array_map('intval', $select->fetchAll(\PDO::FETCH_COLUMN));
            if ($ids === []) {
                $pdo->commit();

                return [];
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare(
                "UPDATE entry_schedules
                 SET status = 'processing', updated_at = now()
                 WHERE id IN ({$placeholders})
                 RETURNING *"
            );
            $update->execute($ids);
            /** @var list<array<string,mixed>> $rows */
            $rows = $update->fetchAll(\PDO::FETCH_ASSOC);
            $pdo->commit();

            return $this->normalizeRows($rows);
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    public function reclaimStale(int $olderThanSeconds = 300): int
    {
        if ($olderThanSeconds < 1) {
            return 0;
        }

        $stmt = $this->db->getPDO()->prepare(
            "UPDATE entry_schedules
             SET status = 'pending', updated_at = now()
             WHERE status = 'processing'
               AND updated_at < (now() - (:seconds::int * interval '1 second'))"
        );
        $stmt->bindValue(':seconds', $olderThanSeconds, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function markOutcome(int $id, ScheduleStatus $status, ?string $failureReason = null): void
    {
        if (!in_array($status, [ScheduleStatus::Done, ScheduleStatus::Failed, ScheduleStatus::Canceled], true)) {
            throw new \InvalidArgumentException('Schedule outcome must be terminal.');
        }

        $stmt = $this->db->getPDO()->prepare(
            "UPDATE entry_schedules
             SET status = :status,
                 attempts = attempts + 1,
                 failure_reason = :failure_reason,
                 updated_at = :updated_at
             WHERE id = :id AND status = 'processing'"
        );
        $stmt->execute([
            ':status' => $status->value,
            ':failure_reason' => $failureReason,
            ':updated_at' => $this->now(),
            ':id' => $id,
        ]);
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        return array_map(fn (array $row): array => $this->normalizeRow($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        foreach (['run_at', 'created_at', 'updated_at', 'canceled_at'] as $key) {
            if (($row[$key] ?? null) === null) {
                continue;
            }

            try {
                $row[$key] = (new \DateTimeImmutable((string) $row[$key], new \DateTimeZone('UTC')))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z');
            } catch (\Exception) {
                // Leave unexpected database values untouched; validation happens on write.
            }
        }

        return $row;
    }
}
