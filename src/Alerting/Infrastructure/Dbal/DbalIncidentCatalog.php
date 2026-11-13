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
use Kadupul\Alerting\Application\ReadModel\SeriesView;

/**
 * Incidents for the incident pages, on the evaluator's connection. An
 * incident is visible through the series it recorded when it opened
 * (alert_incident.local_data_id and rrd_name), never through its rule's
 * current series, so moving a rule does not change who sees its earlier
 * incidents (ADR 0011 decision 12). An incident whose data source is gone
 * is visible only with full device access (SeriesVisibility::seriesVisible).
 * The rule is shown only when it still exists and its current series is
 * visible too. Rows are read through DbalIncidentRepository::fromRow(); one
 * the domain refuses is listed by id as unreadable, never hidden or guessed
 * at. From user_auth only the acknowledging account's username is selected.
 */
final readonly class DbalIncidentCatalog implements IncidentCatalog
{
    /** Notifications listed per incident; Phase 1 queues at most one per transition. */
    public const int NOTIFICATION_LIMIT = 100;

    /** The incident's recorded series, joined as idl and ih. */
    public const string INCIDENT_SERIES_JOINS = 'LEFT JOIN data_local idl ON idl.id = i.local_data_id LEFT JOIN host ih ON ih.id = idl.host_id';

    public function __construct(private Connection $database, private SeriesVisibility $visibility) {}

    public function rowsPerPage(): int
    {
        return DbalRuleCatalog::rows($this->database);
    }

    public function page(int $actorId, bool $activeOnly, int $page): IncidentPage
    {
        $rows = $this->rowsPerPage();
        $where = ' WHERE ' . $this->visibility->seriesVisible($actorId, 'idl') . ($activeOnly ? ' AND i.active_rule_id IS NOT NULL' : '');
        $total = StoredValue::int($this->database->fetchOne('SELECT COUNT(*) FROM alert_incident i ' . self::INCIDENT_SERIES_JOINS . $where), 'alert_incident', 'COUNT(*)');
        $found = $this->database->fetchAllAssociative(
            $this->select($actorId) . $where . ' ORDER BY i.id DESC LIMIT ? OFFSET ?',
            [$rows, ($page - 1) * $rows],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return new IncidentPage(array_map(self::view(...), $found), $total, $page, $rows, $activeOnly);
    }

    public function find(int $actorId, int $incidentId, int $now): ?IncidentDetail
    {
        $row = $this->database->fetchAssociative($this->select($actorId) . ' WHERE i.id = ? AND ' . $this->visibility->seriesVisible($actorId, 'idl'), [$incidentId], [ParameterType::INTEGER]);
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

    private function select(int $actorId): string
    {
        return 'SELECT i.id AS incident_id, i.rule_id AS incident_rule_id, i.status, i.active_rule_id, i.opened_at, i.ended_at, i.acknowledged_at, i.acknowledged_by,
                i.local_data_id AS incident_local_data_id, i.rrd_name AS incident_rrd_name, idl.host_id AS incident_host_id, ih.description AS incident_description,
                CASE WHEN idl.id IS NOT NULL AND (idl.host_id = 0 OR (ih.id IS NOT NULL AND ih.deleted <> \'on\'))
                    AND EXISTS (SELECT 1 FROM data_template_rrd idtr WHERE idtr.local_data_id = i.local_data_id AND idtr.data_source_name = i.rrd_name) THEN 1 ELSE 0 END AS incident_series_exists,
                ua.username AS acknowledged_username,
                CASE WHEN r.id IS NOT NULL AND ' . $this->visibility->ruleVisible($actorId) . ' THEN 1 ELSE 0 END AS rule_visible,
                r.' . implode(', r.', RuleRevision::COLUMNS) . ',
                dl.id AS data_local_id, dl.host_id, h.description,
                CASE WHEN ' . DbalRuleRepository::SERIES_EXISTS . ' THEN 1 ELSE 0 END AS series_exists,
                i2.id AS active_incident_id
            FROM alert_incident i ' . self::INCIDENT_SERIES_JOINS . '
            LEFT JOIN alert_rule r ON r.id = i.rule_id ' . DbalRuleRepository::SERIES_JOINS . '
            LEFT JOIN alert_incident i2 ON i2.active_rule_id = r.id
            LEFT JOIN user_auth ua ON ua.id = i.acknowledged_by';
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): IncidentView
    {
        $id = StoredValue::int($row['incident_id'], 'alert_incident', 'id');
        $where = 'alert_incident ' . $id;
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
        $hasDevice = $row['incident_host_id'] !== null && StoredValue::int($row['incident_host_id'], $where, 'host_id') > 0;
        $series = new SeriesView(
            StoredValue::int($row['incident_local_data_id'], $where, 'local_data_id'),
            is_string($row['incident_rrd_name']) ? $row['incident_rrd_name'] : '',
            StoredValue::int($row['incident_series_exists'], $where, 'series_exists') === 1,
            $hasDevice && is_string($row['incident_description']) && $row['incident_description'] !== '' ? $row['incident_description'] : null,
        );
        $rule = StoredValue::int($row['rule_visible'], $where, 'rule_visible') === 1 ? DbalRuleCatalog::view($row) : null;

        return new IncidentView($id, $incident, $series, StoredValue::int($row['incident_rule_id'], $where, 'rule_id'), $rule, $username);
    }
}
