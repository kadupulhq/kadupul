<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Alerting\Application\Port\IncidentCatalog;
use Kadupul\Alerting\Application\ReadModel\IncidentDetail;
use Kadupul\Alerting\Application\ReadModel\IncidentPage;
use Kadupul\Alerting\Application\ReadModel\IncidentView;
use Kadupul\Alerting\Application\ReadModel\NotificationView;

/**
 * Incidents for the incident pages, on the evaluator's connection. An
 * incident is visible only through its rule (SeriesVisibility::ruleVisible),
 * so a rule's incidents are hidden exactly when the rule is. Rows are read
 * through DbalIncidentRepository::fromRow(); one the domain refuses is listed
 * by id as unreadable, never hidden or guessed at. From user_auth only the
 * acknowledging account's username is selected.
 */
final readonly class DbalIncidentCatalog implements IncidentCatalog
{
    /** Notifications listed per incident; Phase 1 queues at most one per transition. */
    public const int NOTIFICATION_LIMIT = 100;

    public function __construct(private Connection $database, private SeriesVisibility $visibility) {}

    public function rowsPerPage(): int
    {
        return DbalRuleCatalog::rows($this->database);
    }

    public function page(int $actorId, bool $activeOnly, int $page): IncidentPage
    {
        $rows = $this->rowsPerPage();
        $where = ' WHERE ' . $this->visibility->ruleVisible($actorId) . ($activeOnly ? ' AND i.active_rule_id IS NOT NULL' : '');
        $total = StoredValue::int($this->database->fetchOne('SELECT COUNT(*) FROM alert_incident i INNER JOIN alert_rule r ON r.id = i.rule_id ' . DbalRuleRepository::SERIES_JOINS . $where), 'alert_incident', 'COUNT(*)');
        $found = $this->database->fetchAllAssociative(
            self::select() . $where . ' ORDER BY i.id DESC LIMIT ? OFFSET ?',
            [$rows, ($page - 1) * $rows],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return new IncidentPage(array_map(self::view(...), $found), $total, $page, $rows, $activeOnly);
    }

    public function find(int $actorId, int $incidentId, int $now): ?IncidentDetail
    {
        $row = $this->database->fetchAssociative(self::select() . ' WHERE i.id = ? AND ' . $this->visibility->ruleVisible($actorId), [$incidentId], [ParameterType::INTEGER]);
        if ($row === false) {
            return null;
        }
        $notifications = $this->database->fetchAllAssociative(
            'SELECT id, kind, status, attempts, queued_at, next_attempt_at, sent_at, last_error FROM alert_notification WHERE incident_id = ? ORDER BY id LIMIT ?',
            [$incidentId, self::NOTIFICATION_LIMIT],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return new IncidentDetail(self::view($row), array_map(static function (array $row) use ($now): NotificationView {
            $id = StoredValue::int($row['id'], 'alert_notification', 'id');
            $where = 'alert_notification ' . $id;

            return NotificationView::fromStored(
                $id,
                is_string($row['kind']) ? $row['kind'] : '',
                is_string($row['status']) ? $row['status'] : '',
                StoredValue::int($row['attempts'], $where, 'attempts'),
                StoredValue::int($row['queued_at'], $where, 'queued_at'),
                StoredValue::int($row['next_attempt_at'], $where, 'next_attempt_at'),
                StoredValue::nullableInt($row['sent_at'], $where, 'sent_at'),
                is_string($row['last_error']) ? $row['last_error'] : '',
                $now,
            );
        }, $notifications));
    }

    private static function select(): string
    {
        return 'SELECT i.id AS incident_id, i.rule_id AS incident_rule_id, i.status, i.active_rule_id, i.opened_at, i.ended_at, i.acknowledged_at, i.acknowledged_by,
                ua.username AS acknowledged_username,
                r.' . implode(', r.', RuleRevision::COLUMNS) . ',
                dl.id AS data_local_id, dl.host_id, h.description,
                CASE WHEN ' . DbalRuleRepository::SERIES_EXISTS . ' THEN 1 ELSE 0 END AS series_exists,
                i2.id AS active_incident_id
            FROM alert_incident i
            INNER JOIN alert_rule r ON r.id = i.rule_id ' . DbalRuleRepository::SERIES_JOINS . '
            LEFT JOIN alert_incident i2 ON i2.active_rule_id = r.id
            LEFT JOIN user_auth ua ON ua.id = i.acknowledged_by';
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): IncidentView
    {
        $id = StoredValue::int($row['incident_id'], 'alert_incident', 'id');
        try {
            $incident = DbalIncidentRepository::fromRow([
                'id' => $row['incident_id'],
                'rule_id' => $row['incident_rule_id'],
                'status' => $row['status'],
                'active_rule_id' => $row['active_rule_id'],
                'opened_at' => $row['opened_at'],
                'ended_at' => $row['ended_at'],
                'acknowledged_at' => $row['acknowledged_at'],
                'acknowledged_by' => $row['acknowledged_by'],
            ]);
        } catch (StoredAlertingRowUnreadable) {
            $incident = null;
        }
        $username = $incident?->acknowledgedBy !== null && is_string($row['acknowledged_username']) ? $row['acknowledged_username'] : null;

        return new IncidentView($id, $incident, DbalRuleCatalog::view($row), $username);
    }
}
