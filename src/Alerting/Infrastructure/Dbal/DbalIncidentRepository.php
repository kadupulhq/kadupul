<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Kadupul\Alerting\Application\Port\IncidentRepository;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Incident\InvalidIncident;

/**
 * alert_incident keeps active_rule_id equal to rule_id while an incident is
 * open or acknowledged and NULL once it ends; its unique key is what allows
 * one active incident per rule. Every change names the active incident by
 * that column, and a change that finds none, or a second active incident,
 * throws UnusableRuleRecord so the caller's transaction rolls back the rule
 * state with it and the evaluator skips only that rule.
 */
final readonly class DbalIncidentRepository implements IncidentRepository
{
    /** Rule ids per orphaning UPDATE, bounding the IN list. */
    private const int CHUNK = 500;

    public function __construct(private Connection $database) {}

    public function open(int $ruleId, int $observedAt): int
    {
        try {
            // The incident records the series its rule watches now. The
            // evaluator holds the rule row's lock, so this is the series
            // that was evaluated; who may see the incident is decided by it
            // even after the rule moves to another series (ADR 0011 decision 12).
            $inserted = $this->database->executeStatement(
                'INSERT INTO alert_incident (rule_id, status, active_rule_id, opened_at, local_data_id, rrd_name)
                SELECT r.id, ?, r.id, ?, r.local_data_id, r.rrd_name FROM alert_rule r WHERE r.id = ?',
                [IncidentStatus::Open->value, $observedAt, $ruleId],
            );
        } catch (UniqueConstraintViolationException $e) {
            throw new UnusableRuleRecord($ruleId, 'it already has an open or acknowledged incident; its stored state says it has none.', $e);
        }
        if ((int) $inserted !== 1) {
            throw new \RuntimeException('Alert rule ' . $ruleId . ' disappeared while its incident was being opened.');
        }

        return StoredValue::int($this->database->lastInsertId(), 'alert_incident', 'id');
    }

    /**
     * The active incident is read with a row lock and then ended by its id
     * and rule, so the id returned is the incident this call closed.
     */
    public function close(int $ruleId, int $observedAt): int
    {
        $id = $this->database->fetchOne('SELECT id FROM alert_incident WHERE active_rule_id = ?' . RowLock::forUpdate($this->database), [$ruleId]);
        $changed = $id === false ? 0 : $this->database->executeStatement(
            'UPDATE alert_incident SET status = ?, active_rule_id = NULL, ended_at = ? WHERE id = ? AND active_rule_id = ?',
            [IncidentStatus::Closed->value, $observedAt, $id, $ruleId],
        );
        if ($changed !== 1) {
            throw new UnusableRuleRecord($ruleId, 'it recovered, but it has no open or acknowledged incident to close.');
        }

        return StoredValue::int($id, 'alert_incident', 'id');
    }

    public function orphan(array $ruleIds, int $at): int
    {
        $ended = 0;
        foreach (array_chunk(array_values(array_unique($ruleIds)), self::CHUNK) as $ids) {
            $ended += (int) $this->database->executeStatement(
                'UPDATE alert_incident SET status = ?, active_rule_id = NULL, ended_at = ? WHERE active_rule_id IN (?)',
                [IncidentStatus::Orphaned->value, $at, $ids],
                [ParameterType::STRING, ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        }

        return $ended;
    }

    /**
     * Every incident of a rule, oldest first.
     *
     * @return list<Incident>
     */
    public function forRule(int $ruleId): array
    {
        $rows = $this->database->fetchAllAssociative(
            'SELECT id, rule_id, status, active_rule_id, opened_at, ended_at, acknowledged_at, acknowledged_by
            FROM alert_incident WHERE rule_id = ? ORDER BY id',
            [$ruleId],
        );

        return array_map(self::fromRow(...), $rows);
    }

    /**
     * The incident pages read rows through this too.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): Incident
    {
        $id = StoredValue::int($row['id'], 'alert_incident', 'id');
        $where = 'alert_incident ' . $id;
        $status = is_string($row['status']) ? IncidentStatus::tryFrom($row['status']) : null;
        if ($status === null) {
            throw StoredAlertingRowUnreadable::because($where, 'status is not an incident status.');
        }
        $ruleId = StoredValue::int($row['rule_id'], $where, 'rule_id');
        $active = StoredValue::nullableInt($row['active_rule_id'], $where, 'active_rule_id');
        if ($active !== ($status->isActive() ? $ruleId : null)) {
            throw StoredAlertingRowUnreadable::because($where, 'active_rule_id does not match its status and rule.');
        }
        try {
            return new Incident(
                $id,
                $ruleId,
                $status,
                StoredValue::int($row['opened_at'], $where, 'opened_at'),
                StoredValue::nullableInt($row['ended_at'], $where, 'ended_at'),
                StoredValue::nullableInt($row['acknowledged_at'], $where, 'acknowledged_at'),
                StoredValue::nullableInt($row['acknowledged_by'], $where, 'acknowledged_by'),
            );
        } catch (InvalidIncident $e) {
            throw StoredAlertingRowUnreadable::because($where, $e->getMessage(), $e);
        }
    }
}
