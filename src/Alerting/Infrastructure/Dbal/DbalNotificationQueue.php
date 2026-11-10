<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Alerting\Application\Port\ClaimedNotification;
use Kadupul\Alerting\Application\Port\NotificationContent;
use Kadupul\Alerting\Application\Port\NotificationQueue;
use Kadupul\Alerting\Domain\Evaluation\Transition;

/**
 * alert_notification as a queue. Every change after the insert is one
 * conditional UPDATE, so it is atomic on its own: a claim succeeds only for
 * a row that is still due, and a settlement only while the row is `sending`
 * with the lease its claim set. A claim always raises the attempt count, so
 * the lease value of a later claim is always later and never equals an
 * earlier one. No statement depends on SKIP LOCKED or another engine feature
 * the supported MariaDB and MySQL versions do not share.
 *
 * The mail is read from the row and its incident only; the series is joined
 * from alert_rule, data_local and host's description column, so no device
 * credential, setting or query text is ever selected.
 */
final readonly class DbalNotificationQueue implements NotificationQueue
{
    public const string QUEUED = 'queued';
    public const string SENDING = 'sending';
    public const string SENT = 'sent';
    public const string FAILED = 'failed';
    public const string SUPPRESSED = 'suppressed';
    public const string EXPIRED = 'its last attempt did not report back before its claim expired';

    public function __construct(private Connection $database) {}

    public function enqueue(int $incidentId, Transition $kind, float $value, float $threshold, int $queuedAt): void
    {
        if ($kind !== Transition::Opened && $kind !== Transition::Recovered) {
            throw new \InvalidArgumentException('Only an opened or recovered incident is notified, not ' . $kind->value . '.');
        }
        $this->database->insert('alert_notification', [
            'incident_id' => $incidentId,
            'kind' => $kind->value,
            'status' => self::QUEUED,
            'attempts' => 0,
            'queued_at' => $queuedAt,
            'next_attempt_at' => $queuedAt,
            'last_error' => '',
            // A float bound as a string would be cut to the 14 digits of PHP's precision setting.
            'observed_value' => sprintf('%.17G', $value),
            'threshold' => sprintf('%.17G', $threshold),
        ]);
    }

    public function failExpiredClaims(int $now, int $maxAttempts): int
    {
        return (int) $this->database->executeStatement(
            'UPDATE alert_notification SET status = ?, last_error = ? WHERE status = ? AND next_attempt_at <= ? AND attempts >= ?',
            [self::FAILED, self::EXPIRED, self::SENDING, $now, $maxAttempts],
        );
    }

    public function due(int $now, int $maxAttempts, int $limit): array
    {
        $ids = $this->database->fetchFirstColumn(
            'SELECT id FROM alert_notification WHERE status IN (?, ?) AND next_attempt_at <= ? AND attempts < ? ORDER BY next_attempt_at, id LIMIT ?',
            [self::QUEUED, self::SENDING, $now, $maxAttempts, $limit],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_map(static fn(mixed $id): int => StoredValue::int($id, 'alert_notification', 'id'), $ids);
    }

    public function claim(int $id, int $now, int $maxAttempts, int $leaseUntil): ?ClaimedNotification
    {
        $claimed = (int) $this->database->executeStatement(
            'UPDATE alert_notification SET status = ?, attempts = attempts + 1, next_attempt_at = ?
            WHERE id = ? AND status IN (?, ?) AND next_attempt_at <= ? AND attempts < ?',
            [self::SENDING, $leaseUntil, $id, self::QUEUED, self::SENDING, $now, $maxAttempts],
        );
        if ($claimed !== 1) {
            return null;
        }
        $row = $this->database->fetchAssociative(
            'SELECT n.attempts, n.incident_id, n.kind, n.observed_value, n.threshold,
                i.rule_id, i.opened_at, i.ended_at, i.rule_kind, i.local_data_id, i.rrd_name, dl.id AS data_local_id, dl.host_id, h.description
            FROM alert_notification n
            LEFT JOIN alert_incident i ON i.id = n.incident_id
            LEFT JOIN data_local dl ON dl.id = i.local_data_id
            LEFT JOIN host h ON h.id = dl.host_id
            WHERE n.id = ? AND n.status = ? AND n.next_attempt_at = ?',
            [$id, self::SENDING, $leaseUntil],
        );
        if ($row === false) {
            return null;
        }
        $where = 'alert_notification ' . $id;

        return new ClaimedNotification($id, StoredValue::int($row['attempts'], $where, 'attempts'), $leaseUntil, self::content($where, $row));
    }

    public function markSent(ClaimedNotification $claim, int $sentAt): bool
    {
        return (int) $this->database->executeStatement(
            'UPDATE alert_notification SET status = ?, sent_at = ? WHERE id = ? AND status = ? AND next_attempt_at = ?',
            [self::SENT, $sentAt, $claim->id, self::SENDING, $claim->leaseUntil],
        ) === 1;
    }

    public function markSuppressed(ClaimedNotification $claim, string $reason): bool
    {
        return (int) $this->database->executeStatement(
            'UPDATE alert_notification SET status = ?, last_error = ? WHERE id = ? AND status = ? AND next_attempt_at = ?',
            [self::SUPPRESSED, $reason, $claim->id, self::SENDING, $claim->leaseUntil],
        ) === 1;
    }

    public function markAttemptFailed(ClaimedNotification $claim, string $error, ?int $retryAt): bool
    {
        return (int) $this->database->executeStatement(
            'UPDATE alert_notification SET status = ?, next_attempt_at = ?, last_error = ? WHERE id = ? AND status = ? AND next_attempt_at = ?',
            [$retryAt === null ? self::FAILED : self::QUEUED, $retryAt ?? $claim->leaseUntil, $error, $claim->id, self::SENDING, $claim->leaseUntil],
        ) === 1;
    }

    /**
     * Null when the row or its incident cannot be read, so it is failed
     * rather than sent with a guessed value.
     *
     * @param array<string, mixed> $row
     */
    private static function content(string $where, array $row): ?NotificationContent
    {
        $kind = is_string($row['kind']) ? Transition::tryFrom($row['kind']) : null;
        if ($kind !== Transition::Opened && $kind !== Transition::Recovered) {
            return null;
        }
        try {
            if ($row['rule_id'] === null) {
                throw StoredAlertingRowUnreadable::because($where, 'its incident does not exist.');
            }
            $observedAt = $kind === Transition::Opened ? $row['opened_at'] : $row['ended_at'];
            $series = $row['data_local_id'] !== null && is_string($row['rrd_name']);
            $device = $series && StoredValue::int($row['host_id'], $where, 'host_id') > 0 && is_string($row['description']) && $row['description'] !== ''
                ? $row['description']
                : null;

            return new NotificationContent(
                StoredValue::int($row['incident_id'], $where, 'incident_id'),
                StoredValue::int($row['rule_id'], $where, 'rule_id'),
                $kind,
                StoredValue::float($row['observed_value'], $where, 'observed_value'),
                StoredValue::float($row['threshold'], $where, 'threshold'),
                StoredValue::int($observedAt, $where, $kind === Transition::Opened ? 'opened_at' : 'ended_at'),
                $series ? StoredValue::int($row['local_data_id'], $where, 'local_data_id') : null,
                $series ? $row['rrd_name'] : null,
                $device,
                is_string($row['rule_kind']) ? $row['rule_kind'] : null,
            );
        } catch (StoredAlertingRowUnreadable) {
            return null;
        }
    }
}
