<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Domain\Evaluation\InvalidRuleState;
use Kadupul\Alerting\Domain\Evaluation\RuleState;

/**
 * One alert_rule_state row per rule. Every RuleState field is stored,
 * including the unknown flag, so an incident reported unknown is not reported
 * again after a reload. A row that RuleState refuses is reported.
 */
final readonly class DbalRuleStateStore implements RuleStateStore
{
    /** Rule ids per query, bounding the IN list. */
    private const int CHUNK = 500;

    public function __construct(private Connection $database) {}

    public function load(array $ruleIds): array
    {
        $states = [];
        foreach (array_chunk(array_values(array_unique($ruleIds)), self::CHUNK) as $ids) {
            $rows = $this->database->fetchAllAssociative(
                'SELECT rule_id, incident_open, consecutive_breaches, consecutive_oks, is_unknown, last_observed_at
                FROM alert_rule_state WHERE rule_id IN (?)',
                [$ids],
                [ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $id = StoredValue::int($row['rule_id'], 'alert_rule_state', 'rule_id');
                $states[$id] = self::state($id, $row);
            }
        }

        return $states;
    }

    public function save(int $ruleId, RuleState $state): void
    {
        $this->database->executeStatement(
            'REPLACE INTO alert_rule_state (rule_id, incident_open, consecutive_breaches, consecutive_oks, is_unknown, last_observed_at)
            VALUES (?, ?, ?, ?, ?, ?)',
            [$ruleId, (int) $state->isOpen(), $state->consecutiveBreaches(), $state->consecutiveOks(), (int) $state->isUnknown(), $state->lastObservedAt()],
        );
    }

    public function delete(int $ruleId): void
    {
        $this->database->executeStatement('DELETE FROM alert_rule_state WHERE rule_id = ?', [$ruleId]);
    }

    /** @param array<string, mixed> $row */
    private static function state(int $id, array $row): RuleState
    {
        $where = 'alert_rule_state ' . $id;
        try {
            return new RuleState(
                StoredValue::flag($row['incident_open'], $where, 'incident_open'),
                StoredValue::int($row['consecutive_breaches'], $where, 'consecutive_breaches'),
                StoredValue::int($row['consecutive_oks'], $where, 'consecutive_oks'),
                StoredValue::flag($row['is_unknown'], $where, 'is_unknown'),
                StoredValue::nullableInt($row['last_observed_at'], $where, 'last_observed_at'),
            );
        } catch (InvalidRuleState $e) {
            throw StoredAlertingRowUnreadable::because($where, $e->getMessage(), $e);
        }
    }
}
