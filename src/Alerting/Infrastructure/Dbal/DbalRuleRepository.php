<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Alerting\Application\Port\RuleRepository;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\Rule\AlertRule;
use Kadupul\Alerting\Domain\Rule\BaselineDirection;
use Kadupul\Alerting\Domain\Rule\BaselineRule;
use Kadupul\Alerting\Domain\Rule\Comparator;
use Kadupul\Alerting\Domain\Rule\InvalidRule;
use Kadupul\Alerting\Domain\Rule\SeriesKey;
use Kadupul\Alerting\Domain\Rule\ThresholdRule;

/**
 * Rules from alert_rule. rrd_name is varbinary, so a rule names one exact
 * data source name, and comparing it with data_template_rrd.data_source_name
 * is a byte comparison whatever that column's collation.
 *
 * kind is threshold or baseline. Each kind has its own columns, and a row
 * must set those and leave the other kind's NULL; any other row is returned
 * as that rule's UnusableRuleRecord, never guessed at or defaulted.
 */
final readonly class DbalRuleRepository implements RuleRepository
{
    /**
     * The rule r's series exists: its data_local row, its device unless
     * host_id is 0 (a data source without a device), and the RRD data source
     * name on that data source. A device a remote collector has only marked
     * deleted (lib/api_device.php) counts as gone. A disabled device or data
     * source still exists. Never NULL, so NOT (...) is its exact complement.
     */
    private const string SERIES_EXISTS = "dl.id IS NOT NULL
        AND (dl.host_id = 0 OR (h.id IS NOT NULL AND h.deleted <> 'on'))
        AND EXISTS (SELECT 1 FROM data_template_rrd dtr WHERE dtr.local_data_id = r.local_data_id AND dtr.data_source_name = r.rrd_name)";
    private const string SERIES_JOINS = 'LEFT JOIN data_local dl ON dl.id = r.local_data_id LEFT JOIN host h ON h.id = dl.host_id';
    private const array THRESHOLD_COLUMNS = ['comparator', 'trigger_threshold', 'clear_threshold'];
    private const array BASELINE_COLUMNS = ['baseline_direction', 'baseline_deviations', 'baseline_clear_deviations', 'baseline_floor', 'baseline_min_samples'];

    public function __construct(private Connection $database) {}

    /**
     * Only rules whose series exists: a data source deleted while its last
     * rate is still fresh must not open an incident again after its rule was
     * orphaned.
     */
    public function batchAfter(int $afterRuleId, int $limit): array
    {
        $rows = $this->database->fetchAllAssociative(
            'SELECT r.id, r.local_data_id, r.rrd_name, r.kind, r.comparator, r.trigger_threshold, r.clear_threshold,
                r.baseline_direction, r.baseline_deviations, r.baseline_clear_deviations, r.baseline_floor, r.baseline_min_samples, r.for_cycles
            FROM alert_rule r ' . self::SERIES_JOINS . '
            WHERE r.id > ? AND ' . self::SERIES_EXISTS . '
            ORDER BY r.id LIMIT ?',
            [$afterRuleId, $limit],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return array_map(self::rule(...), $rows);
    }

    /** SELECT ... FOR UPDATE on the rule row, so a concurrent delete waits for this transaction. */
    public function lock(int $ruleId): bool
    {
        return $this->database->fetchOne('SELECT id FROM alert_rule WHERE id = ?' . RowLock::forUpdate($this->database), [$ruleId]) !== false;
    }

    /**
     * Rules whose series is gone (the complement of SERIES_EXISTS), plus
     * deleted rules, that still have a state or an active incident to end.
     * A disabled device keeps its rules: their series goes stale and reads as
     * unknown, and the incident stays open. A rule with nothing to end is not
     * returned, so an orphan is handled once and then left alone.
     */
    public function orphanedRuleIds(int $limit): array
    {
        $ids = $this->database->fetchFirstColumn(
            'SELECT r.id FROM alert_rule r ' . self::SERIES_JOINS . '
            WHERE NOT (' . self::SERIES_EXISTS . ')
            AND (EXISTS (SELECT 1 FROM alert_rule_state s WHERE s.rule_id = r.id)
                OR EXISTS (SELECT 1 FROM alert_incident i WHERE i.active_rule_id = r.id))
            UNION
            SELECT s.rule_id FROM alert_rule_state s WHERE NOT EXISTS (SELECT 1 FROM alert_rule r WHERE r.id = s.rule_id)
            UNION
            SELECT i.rule_id FROM alert_incident i WHERE i.active_rule_id IS NOT NULL
                AND NOT EXISTS (SELECT 1 FROM alert_rule r WHERE r.id = i.rule_id)
            ORDER BY 1 LIMIT ?',
            [$limit],
            [ParameterType::INTEGER],
        );

        return array_map(static fn(mixed $id): int => StoredValue::int($id, 'alert_rule', 'id'), $ids);
    }

    /**
     * A row the domain refuses becomes that rule's UnusableRuleRecord. An
     * unreadable primary key names no rule and is thrown.
     *
     * @param array<string, mixed> $row
     */
    private static function rule(array $row): AlertRule|UnusableRuleRecord
    {
        $id = StoredValue::int($row['id'], 'alert_rule', 'id');
        $where = 'alert_rule ' . $id;
        if (!is_string($row['rrd_name'])) {
            return new UnusableRuleRecord($id, StoredAlertingRowUnreadable::because($where, 'rrd_name is not one the rule form writes.')->getMessage());
        }
        try {
            return new AlertRule(
                $id,
                new SeriesKey(StoredValue::int($row['local_data_id'], $where, 'local_data_id'), $row['rrd_name']),
                match ($row['kind']) {
                    'threshold' => self::threshold($row, $where),
                    'baseline' => self::baseline($row, $where),
                    default => throw StoredAlertingRowUnreadable::because($where, 'kind is not one the rule form writes.'),
                },
            );
        } catch (InvalidRule $e) {
            return new UnusableRuleRecord($id, StoredAlertingRowUnreadable::because($where, $e->getMessage())->getMessage(), $e);
        } catch (StoredAlertingRowUnreadable $e) {
            return new UnusableRuleRecord($id, $e->getMessage(), $e);
        }
    }

    /** @param array<string, mixed> $row */
    private static function threshold(array $row, string $where): ThresholdRule
    {
        self::requireUnset($row, self::BASELINE_COLUMNS, $where, 'a threshold rule');
        $comparator = is_string($row['comparator']) ? Comparator::tryFrom($row['comparator']) : null;
        if ($comparator === null) {
            throw StoredAlertingRowUnreadable::because($where, 'comparator is not one the rule form writes.');
        }

        return new ThresholdRule(
            $comparator,
            StoredValue::float($row['trigger_threshold'], $where, 'trigger_threshold'),
            StoredValue::float($row['clear_threshold'], $where, 'clear_threshold'),
            StoredValue::int($row['for_cycles'], $where, 'for_cycles'),
        );
    }

    /** @param array<string, mixed> $row */
    private static function baseline(array $row, string $where): BaselineRule
    {
        self::requireUnset($row, self::THRESHOLD_COLUMNS, $where, 'a baseline rule');
        $direction = is_string($row['baseline_direction']) ? BaselineDirection::tryFrom($row['baseline_direction']) : null;
        if ($direction === null) {
            throw StoredAlertingRowUnreadable::because($where, 'baseline_direction is not one the rule form writes.');
        }

        return new BaselineRule(
            StoredValue::float($row['baseline_deviations'], $where, 'baseline_deviations'),
            StoredValue::float($row['baseline_clear_deviations'], $where, 'baseline_clear_deviations'),
            StoredValue::float($row['baseline_floor'], $where, 'baseline_floor'),
            StoredValue::int($row['for_cycles'], $where, 'for_cycles'),
            $direction,
            StoredValue::int($row['baseline_min_samples'], $where, 'baseline_min_samples'),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $columns
     */
    private static function requireUnset(array $row, array $columns, string $where, string $kind): void
    {
        foreach ($columns as $column) {
            if ($row[$column] !== null) {
                throw StoredAlertingRowUnreadable::because($where, $column . ' is set on ' . $kind . '.');
            }
        }
    }
}
