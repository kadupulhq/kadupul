<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Kadupul\Alerting\Application\Port\IncidentRepository;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Domain\Incident\IncidentStatus;
use Kadupul\Alerting\Domain\Incident\InvalidIncident;

/**
 * alert_incident keeps active_rule_id equal to rule_id while an incident is
 * open or acknowledged and NULL once it ends; its unique key is what allows
 * one active incident per rule. Every change names the active incident by
 * that column, and a change that finds none, or a second active incident,
 * fails so the caller's transaction rolls back the rule state with it.
 */
final readonly class DbalIncidentRepository implements IncidentRepository
{
    public function __construct(private Connection $database) {}

    public function open(int $ruleId, int $observedAt): void
    {
        try {
            $this->database->executeStatement(
                'INSERT INTO alert_incident (rule_id, status, active_rule_id, opened_at) VALUES (?, ?, ?, ?)',
                [$ruleId, IncidentStatus::Open->value, $ruleId, $observedAt],
            );
        } catch (UniqueConstraintViolationException $e) {
            throw new \LogicException('Rule ' . $ruleId . ' already has an open or acknowledged incident; its stored state says it has none.', 0, $e);
        }
    }

    public function close(int $ruleId, int $observedAt): void
    {
        if (!$this->end($ruleId, IncidentStatus::Closed, $observedAt)) {
            throw new \LogicException('Rule ' . $ruleId . ' recovered, but it has no open or acknowledged incident to close.');
        }
    }

    public function orphan(int $ruleId, int $at): bool
    {
        return $this->end($ruleId, IncidentStatus::Orphaned, $at);
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

        return array_map(self::incident(...), $rows);
    }

    private function end(int $ruleId, IncidentStatus $status, int $at): bool
    {
        $changed = $this->database->executeStatement(
            'UPDATE alert_incident SET status = ?, active_rule_id = NULL, ended_at = ? WHERE active_rule_id = ?',
            [$status->value, $at, $ruleId],
        );

        return $changed === 1;
    }

    /** @param array<string, mixed> $row */
    private static function incident(array $row): Incident
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
