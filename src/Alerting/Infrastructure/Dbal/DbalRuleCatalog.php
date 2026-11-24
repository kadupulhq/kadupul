<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\Alerting\Application\Port\RuleCatalog;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Application\ReadModel\RulePage;
use Kadupul\Alerting\Application\ReadModel\RuleView;

/**
 * Rules for the rule pages, on the evaluator's connection. Rows are read
 * through DbalRuleRepository::fromRow(), so a row the evaluator skips as
 * unusable is shown as unusable, and SERIES_EXISTS decides whether the
 * series still exists. Only the device description is selected from host.
 */
final readonly class DbalRuleCatalog implements RuleCatalog
{
    public function __construct(private Connection $database, private SeriesVisibility $visibility) {}

    public function rowsPerPage(): int
    {
        return self::rows($this->database);
    }

    public function page(int $actorId, int $page): RulePage
    {
        $rows = $this->rowsPerPage();
        $visible = $this->visibility->ruleVisible($actorId);
        $total = StoredValue::int($this->database->fetchOne('SELECT COUNT(*) FROM alert_rule r ' . DbalRuleRepository::SERIES_JOINS . ' WHERE ' . $visible), 'alert_rule', 'COUNT(*)');
        $found = $this->database->fetchAllAssociative(
            self::select() . ' WHERE ' . $visible . ' ORDER BY r.id LIMIT ? OFFSET ?',
            [$rows, ($page - 1) * $rows],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return new RulePage(array_map(self::view(...), $found), $total, $page, $rows);
    }

    public function find(int $actorId, int $ruleId): ?RuleView
    {
        $row = $this->database->fetchAssociative(self::select() . ' WHERE r.id = ? AND ' . $this->visibility->ruleVisible($actorId), [$ruleId], [ParameterType::INTEGER]);

        return $row === false ? null : self::view($row);
    }

    /** The rule columns, the series fields the pages show and the active incident, joined on alert_rule `r`. */
    public static function select(): string
    {
        return 'SELECT r.' . implode(', r.', RuleRevision::COLUMNS) . ',
                dl.id AS data_local_id, dl.host_id, h.description,
                CASE WHEN ' . DbalRuleRepository::SERIES_EXISTS . ' THEN 1 ELSE 0 END AS series_exists,
                (SELECT i.id FROM alert_incident i WHERE i.active_rule_id = r.id) AS active_incident_id
            FROM alert_rule r ' . DbalRuleRepository::SERIES_JOINS;
    }

    /** @param array<string, mixed> $row a row of select() */
    public static function view(array $row): RuleView
    {
        $rule = DbalRuleRepository::fromRow($row);
        $id = StoredValue::int($row['id'], 'alert_rule', 'id');
        $where = 'alert_rule ' . $id;
        $hasDevice = $row['data_local_id'] !== null && StoredValue::int($row['host_id'], $where, 'host_id') > 0;

        return new RuleView(
            $id,
            StoredValue::int($row['local_data_id'], $where, 'local_data_id'),
            is_string($row['rrd_name']) ? $row['rrd_name'] : '',
            StoredValue::int($row['series_exists'], $where, 'series_exists') === 1,
            $hasDevice && is_string($row['description']) && $row['description'] !== '' ? $row['description'] : null,
            $rule instanceof UnusableRuleRecord ? null : $rule->condition,
            $rule instanceof UnusableRuleRecord ? $rule->reason : null,
            RuleRevision::of($row),
            StoredValue::nullableInt($row['active_incident_id'], $where, 'active_incident_id'),
        );
    }

    /** num_rows_table as read_config_option() reads it: 1 to 5000, otherwise 25. */
    public static function rows(Connection $database): int
    {
        $rows = filter_var($database->fetchOne("SELECT value FROM settings WHERE name = 'num_rows_table'"), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);

        return $rows === false ? 25 : $rows;
    }
}
