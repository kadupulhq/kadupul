<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\ClaimedNotification;
use Kadupul\Alerting\Application\Port\NotificationContent;
use Kadupul\Alerting\Application\Port\NotificationQueue;
use Kadupul\Alerting\Domain\Evaluation\Transition;

/** The queue's states and conditional writes as DbalNotificationQueue performs them. */
final class InMemoryNotificationQueue implements NotificationQueue
{
    /**
     * @var array<int, array{incident: int, kind: Transition, value: float, threshold: float, status: string, attempts: int, queued: int, next: int, sent: ?int, error: string}>
     */
    public array $rows = [];
    /** Thrown by the next enqueue(), to model a failed insert. */
    public ?\Throwable $failure = null;
    /** @var array<int, true> rows whose content cannot be read */
    public array $unreadable = [];
    /** @var array<int, array{?int, ?string, ?string}> local data id, rrd name and device by rule id */
    public array $series = [];
    private int $lastId = 0;

    public function __construct(private readonly ?InMemoryIncidentRepository $incidents = null) {}

    public function enqueue(int $incidentId, Transition $kind, float $value, float $threshold, int $queuedAt): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($kind !== Transition::Opened && $kind !== Transition::Recovered) {
            throw new \InvalidArgumentException('Only an opened or recovered incident is notified.');
        }
        $this->rows[++$this->lastId] = ['incident' => $incidentId, 'kind' => $kind, 'value' => $value, 'threshold' => $threshold, 'status' => 'queued', 'attempts' => 0, 'queued' => $queuedAt, 'next' => $queuedAt, 'sent' => null, 'error' => ''];
    }

    public function failExpiredClaims(int $now, int $maxAttempts): int
    {
        $failed = 0;
        foreach ($this->rows as $id => $row) {
            if ($row['status'] === 'sending' && $row['next'] <= $now && $row['attempts'] >= $maxAttempts) {
                $this->rows[$id]['status'] = 'failed';
                $this->rows[$id]['error'] = 'its last attempt did not report back before its claim expired';
                ++$failed;
            }
        }

        return $failed;
    }

    public function due(int $now, int $maxAttempts, int $limit): array
    {
        $due = array_filter($this->rows, static fn(array $row): bool => in_array($row['status'], ['queued', 'sending'], true) && $row['next'] <= $now && $row['attempts'] < $maxAttempts);
        uksort($due, static fn(int $a, int $b): int => [$due[$a]['next'], $a] <=> [$due[$b]['next'], $b]);

        return array_slice(array_keys($due), 0, $limit);
    }

    public function claim(int $id, int $now, int $maxAttempts, int $leaseUntil): ?ClaimedNotification
    {
        $row = $this->rows[$id] ?? null;
        if ($row === null || !in_array($row['status'], ['queued', 'sending'], true) || $row['next'] > $now || $row['attempts'] >= $maxAttempts) {
            return null;
        }
        $this->rows[$id]['status'] = 'sending';
        $this->rows[$id]['next'] = $leaseUntil;
        $attempts = ++$this->rows[$id]['attempts'];

        return new ClaimedNotification($id, $attempts, $leaseUntil, $this->content($id));
    }

    public function markSent(ClaimedNotification $claim, int $sentAt): bool
    {
        if (!$this->holds($claim)) {
            return false;
        }
        $this->rows[$claim->id]['status'] = 'sent';
        $this->rows[$claim->id]['sent'] = $sentAt;

        return true;
    }

    public function markAttemptFailed(ClaimedNotification $claim, string $error, ?int $retryAt): bool
    {
        if (!$this->holds($claim)) {
            return false;
        }
        $this->rows[$claim->id]['status'] = $retryAt === null ? 'failed' : 'queued';
        $this->rows[$claim->id]['next'] = $retryAt ?? $claim->leaseUntil;
        $this->rows[$claim->id]['error'] = $error;

        return true;
    }

    /** @return array<string, int> row count by status */
    public function statuses(): array
    {
        $counts = array_count_values(array_column($this->rows, 'status'));
        ksort($counts);

        return $counts;
    }

    private function holds(ClaimedNotification $claim): bool
    {
        $row = $this->rows[$claim->id] ?? null;

        return $row !== null && $row['status'] === 'sending' && $row['next'] === $claim->leaseUntil;
    }

    private function content(int $id): ?NotificationContent
    {
        $row = $this->rows[$id];
        $incident = $this->incidents?->incidents[$row['incident']] ?? null;
        if (isset($this->unreadable[$id]) || $incident === null) {
            return null;
        }
        [$localDataId, $rrdName, $device] = $this->series[$incident['rule']] ?? [null, null, null];

        return new NotificationContent(
            $row['incident'],
            $incident['rule'],
            $row['kind'],
            $row['value'],
            $row['threshold'],
            $row['kind'] === Transition::Opened ? $incident['opened'] : (int) $incident['ended'],
            $localDataId,
            $rrdName,
            $device,
        );
    }
}
