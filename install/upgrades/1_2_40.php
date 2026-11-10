<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Add the native alerting tables (ADR 0011 decision 13). The step creates
 * tables and nothing else: no setting, rule or schedule, so nothing is
 * evaluated or sent until an administrator turns alerting on (decision 10).
 * Each table is created only when missing and then confirmed. A table of
 * another shape, or one that could not be created, stops the upgrade before
 * the version is recorded and is left as it is, so a rerun starts from the
 * same place.
 */
function upgrade_to_1_2_40(): void
{
    foreach (upgrade_alerting_tables() as $table => $definition) {
        db_install_execute($definition['create']);

        $found = db_install_fetch_cell(
            'SELECT CONCAT_WS(\'|\', t.ENGINE, t.TABLE_COLLATION,
                (SELECT GROUP_CONCAT(CONCAT_WS(\':\', c.COLUMN_NAME, c.DATA_TYPE, IF(c.COLUMN_TYPE LIKE \'%unsigned%\', \'unsigned\', \'signed\'), c.IS_NULLABLE) ORDER BY c.ORDINAL_POSITION)
                    FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME),
                (SELECT GROUP_CONCAT(CONCAT_WS(\':\', s.INDEX_NAME, s.NON_UNIQUE, s.COLUMN_NAME) ORDER BY CAST(s.INDEX_NAME AS BINARY), s.SEQ_IN_INDEX)
                    FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME))
            FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?',
            array($table)
        );
        if ($found['data'] !== upgrade_alerting_signature($definition)) {
            throw new RuntimeException('Table ' . $table . ' is missing or differs from the alerting definition. Review it and rerun the upgrade; existing rows are not changed.');
        }
    }
}

/**
 * What upgrade_to_1_2_40() reads back from information_schema for a table
 * that matches its definition.
 *
 * @param array{columns: array<string, string>, indexes: list<string>} $definition
 */
function upgrade_alerting_signature(array $definition): string
{
    $columns = array();
    foreach ($definition['columns'] as $name => $shape) {
        $columns[] = $name . ':' . $shape;
    }
    return 'InnoDB|utf8mb4_unicode_ci|' . implode(',', $columns) . '|' . implode(',', $definition['indexes']);
}

/**
 * The same definitions as cacti.sql; a test compares the two. Times are Unix
 * seconds, so no column depends on a session time zone. alert_rule.kind is
 * threshold or baseline, and only that kind's columns are set.
 * alert_baseline_sample holds one bucket per series and wall-clock hour of
 * the installation time zone, the evaluator's history for baseline rules. alert_incident keeps
 * active_rule_id equal to rule_id while an incident is open or acknowledged
 * and NULL after it ends, so its unique key allows one active incident per
 * rule. Columns read data type:signedness:nullability; indexes read
 * name:non_unique:column per indexed column, ordered by the binary index name
 * and then the column's place in the index.
 *
 * @return array<string, array{create: string, columns: array<string, string>, indexes: list<string>}>
 */
function upgrade_alerting_tables(): array
{
    return array(
        'alert_rule' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS alert_rule (
  id int(10) unsigned NOT NULL auto_increment,
  local_data_id int(10) unsigned NOT NULL,
  rrd_name varbinary(19) NOT NULL,
  kind varchar(16) NOT NULL default \'threshold\',
  comparator char(2) default NULL,
  trigger_threshold double default NULL,
  clear_threshold double default NULL,
  baseline_direction varchar(8) default NULL,
  baseline_deviations double default NULL,
  baseline_clear_deviations double default NULL,
  baseline_floor double default NULL,
  baseline_min_samples tinyint(3) unsigned default NULL,
  for_cycles smallint(5) unsigned NOT NULL default 1,
  owner_id mediumint(8) unsigned NOT NULL default 0,
  PRIMARY KEY (id),
  KEY series (local_data_id, rrd_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array(
                'id' => 'int:unsigned:NO',
                'local_data_id' => 'int:unsigned:NO',
                'rrd_name' => 'varbinary:signed:NO',
                'kind' => 'varchar:signed:NO',
                'comparator' => 'char:signed:YES',
                'trigger_threshold' => 'double:signed:YES',
                'clear_threshold' => 'double:signed:YES',
                'baseline_direction' => 'varchar:signed:YES',
                'baseline_deviations' => 'double:signed:YES',
                'baseline_clear_deviations' => 'double:signed:YES',
                'baseline_floor' => 'double:signed:YES',
                'baseline_min_samples' => 'tinyint:unsigned:YES',
                'for_cycles' => 'smallint:unsigned:NO',
                'owner_id' => 'mediumint:unsigned:NO',
            ),
            'indexes' => array('PRIMARY:0:id', 'series:1:local_data_id', 'series:1:rrd_name'),
        ),
        'alert_rule_state' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS alert_rule_state (
  rule_id int(10) unsigned NOT NULL,
  incident_open tinyint(3) unsigned NOT NULL default 0,
  consecutive_breaches int(10) unsigned NOT NULL default 0,
  consecutive_oks int(10) unsigned NOT NULL default 0,
  is_unknown tinyint(3) unsigned NOT NULL default 0,
  last_observed_at int(10) unsigned default NULL,
  PRIMARY KEY (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array(
                'rule_id' => 'int:unsigned:NO',
                'incident_open' => 'tinyint:unsigned:NO',
                'consecutive_breaches' => 'int:unsigned:NO',
                'consecutive_oks' => 'int:unsigned:NO',
                'is_unknown' => 'tinyint:unsigned:NO',
                'last_observed_at' => 'int:unsigned:YES',
            ),
            'indexes' => array('PRIMARY:0:rule_id'),
        ),
        'alert_baseline_sample' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS alert_baseline_sample (
  local_hour int(10) unsigned NOT NULL,
  local_data_id int(10) unsigned NOT NULL,
  rrd_name varbinary(19) NOT NULL,
  sample_count int(10) unsigned NOT NULL,
  sample_sum double NOT NULL,
  last_observed_at int(10) unsigned NOT NULL,
  PRIMARY KEY (local_hour, local_data_id, rrd_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array(
                'local_hour' => 'int:unsigned:NO',
                'local_data_id' => 'int:unsigned:NO',
                'rrd_name' => 'varbinary:signed:NO',
                'sample_count' => 'int:unsigned:NO',
                'sample_sum' => 'double:signed:NO',
                'last_observed_at' => 'int:unsigned:NO',
            ),
            'indexes' => array('PRIMARY:0:local_hour', 'PRIMARY:0:local_data_id', 'PRIMARY:0:rrd_name'),
        ),
        'alert_incident' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS alert_incident (
  id int(10) unsigned NOT NULL auto_increment,
  rule_id int(10) unsigned NOT NULL,
  status varchar(16) NOT NULL,
  active_rule_id int(10) unsigned default NULL,
  opened_at int(10) unsigned NOT NULL,
  acknowledged_at int(10) unsigned default NULL,
  acknowledged_by mediumint(8) unsigned default NULL,
  ended_at int(10) unsigned default NULL,
  PRIMARY KEY (id),
  UNIQUE KEY active_rule_id (active_rule_id),
  KEY rule_id (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array(
                'id' => 'int:unsigned:NO',
                'rule_id' => 'int:unsigned:NO',
                'status' => 'varchar:signed:NO',
                'active_rule_id' => 'int:unsigned:YES',
                'opened_at' => 'int:unsigned:NO',
                'acknowledged_at' => 'int:unsigned:YES',
                'acknowledged_by' => 'mediumint:unsigned:YES',
                'ended_at' => 'int:unsigned:YES',
            ),
            'indexes' => array('PRIMARY:0:id', 'active_rule_id:0:active_rule_id', 'rule_id:1:rule_id'),
        ),
        'alert_notification' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS alert_notification (
  id bigint(20) unsigned NOT NULL auto_increment,
  incident_id int(10) unsigned NOT NULL,
  kind varchar(16) NOT NULL,
  status varchar(16) NOT NULL default \'queued\',
  attempts smallint(5) unsigned NOT NULL default 0,
  queued_at int(10) unsigned NOT NULL,
  next_attempt_at int(10) unsigned NOT NULL,
  sent_at int(10) unsigned default NULL,
  last_error varchar(255) NOT NULL default \'\',
  observed_value double NOT NULL,
  threshold double NOT NULL,
  PRIMARY KEY (id),
  KEY status_next_attempt (status, next_attempt_at),
  KEY incident_id (incident_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array(
                'id' => 'bigint:unsigned:NO',
                'incident_id' => 'int:unsigned:NO',
                'kind' => 'varchar:signed:NO',
                'status' => 'varchar:signed:NO',
                'attempts' => 'smallint:unsigned:NO',
                'queued_at' => 'int:unsigned:NO',
                'next_attempt_at' => 'int:unsigned:NO',
                'sent_at' => 'int:unsigned:YES',
                'last_error' => 'varchar:signed:NO',
                'observed_value' => 'double:signed:NO',
                'threshold' => 'double:signed:NO',
            ),
            'indexes' => array('PRIMARY:0:id', 'incident_id:1:incident_id', 'status_next_attempt:1:status', 'status_next_attempt:1:next_attempt_at'),
        ),
    );
}
