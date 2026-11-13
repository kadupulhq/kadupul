<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Add the native alerting tables (ADR 0011 decision 13) and grant the two
 * alerting realms to the admin account (decision 12). The step writes no
 * setting, rule or schedule, so nothing is evaluated or sent until an
 * administrator turns alerting on (decision 10). Each table is created only
 * when missing and then confirmed. A table of another shape, or one that
 * could not be created, stops the upgrade before the version is recorded and
 * is left as it is, so a rerun starts from the same place.
 *
 * It also adds one BTREE index to the dsstats hourly cache, which the alert
 * rate read needs (upgrade_dsstats_cache_index()).
 */
function upgrade_to_1_2_40(): void
{
    foreach (upgrade_alerting_tables() as $table => $create) {
        db_install_execute($create);

        $found = db_install_fetch_cell(
            'SELECT CONCAT_WS(\'|\', t.ENGINE, t.TABLE_COLLATION,
                (SELECT GROUP_CONCAT(CONCAT_WS(\':\', c.COLUMN_NAME, c.DATA_TYPE, IF(c.COLUMN_TYPE LIKE \'%unsigned%\', \'unsigned\', \'signed\'), c.IS_NULLABLE) ORDER BY c.ORDINAL_POSITION)
                    FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME),
                (SELECT GROUP_CONCAT(CONCAT_WS(\':\', s.INDEX_NAME, s.NON_UNIQUE, s.COLUMN_NAME) ORDER BY CAST(s.INDEX_NAME AS BINARY), s.SEQ_IN_INDEX)
                    FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME))
            FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?',
            array($table)
        );
        if ($found['data'] !== upgrade_alerting_signature($create)) {
            throw new RuntimeException('Table ' . $table . ' is missing or differs from the alerting definition. Review it and rerun the upgrade; existing rows are not changed.');
        }
    }

    upgrade_dsstats_cache_index();
    upgrade_alerting_realms();
}

/**
 * Index data_source_stats_hourly_cache by data source, series and time. The
 * table's primary key is a HASH key on (local_data_id, time, rrd_name), which
 * serves only a lookup on all three columns, so the alert rate read, which
 * knows the data source and wants the newest time, scanned the whole table.
 * The BTREE key lets it seek. The table is a MEMORY cache that dsstats
 * refills every cycle; adding the key keeps its rows. The key is added only
 * when missing and then confirmed, so a rerun changes nothing, and a table
 * that is missing (dsstats not installed) is left alone.
 */
function upgrade_dsstats_cache_index(): void
{
    $table = db_install_fetch_cell('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'data_source_stats_hourly_cache\'');
    if ((int) ($table['data'] ?? 0) === 0 || db_index_exists('data_source_stats_hourly_cache', 'local_data_id')) {
        return;
    }
    $failure = 'The index local_data_id could not be added to data_source_stats_hourly_cache. Check max_heap_table_size and rerun the upgrade.';
    if (db_install_execute('ALTER TABLE data_source_stats_hourly_cache ADD INDEX local_data_id USING BTREE (local_data_id, rrd_name, time)') !== DB_STATUS_SUCCESS
        || !db_index_exists('data_source_stats_hourly_cache', 'local_data_id')) {
        throw new RuntimeException($failure);
    }
}

/**
 * Alert Rules (29) and Alert Incidents (30) for user 1, the admin account
 * cacti.sql creates, as the 1.0.0 step granted its new realms. Other users
 * and groups get them through user administration. The grant ignores a
 * row an administrator already made, so a rerun changes nothing; a failed
 * grant stops the upgrade before the version is recorded.
 */
function upgrade_alerting_realms(): void
{
    $admin = db_install_fetch_cell('SELECT id FROM user_auth WHERE id = 1');
    if (empty($admin['data'])) {
        return;
    }
    $failure = 'The alerting realms could not be granted to the admin account. Review user_auth_realm and rerun the upgrade.';
    foreach (array(29, 30) as $realm) {
        if (db_install_execute('INSERT IGNORE INTO user_auth_realm (realm_id, user_id) VALUES (?, 1)', array($realm)) !== DB_STATUS_SUCCESS) {
            throw new RuntimeException($failure);
        }
    }
    // The IGNORE modifier also skips a row the table refuses (MySQL turns a
    // CHECK or conversion failure into a warning), so confirm both grants exist.
    $granted = db_install_fetch_cell('SELECT COUNT(*) FROM user_auth_realm WHERE user_id = 1 AND realm_id IN (29, 30)');
    if ((int) ($granted['data'] ?? 0) !== 2) {
        throw new RuntimeException($failure);
    }
}

/**
 * What upgrade_to_1_2_40() reads back from information_schema for a table
 * created by $create: each column as name:data type:signedness:nullability in
 * column order, then each indexed column as name:non_unique:column, ordered by
 * the binary index name and then the column's place in the index.
 */
function upgrade_alerting_signature(string $create): string
{
    $columns = array();
    $indexes = array();
    foreach (array_slice(preg_split('/\R/', trim($create)), 1, -1) as $line) {
        $line = rtrim(trim($line), ',');
        if (preg_match('/^(PRIMARY|UNIQUE)? ?KEY ?(\w*) \(([^)]+)\)$/', $line, $key)) {
            $name = $key[1] === 'PRIMARY' ? 'PRIMARY' : $key[2];
            foreach (array_map('trim', explode(',', $key[3])) as $column) {
                $indexes[$name][] = $name . ':' . ($key[1] === '' ? '1' : '0') . ':' . $column;
            }
        } elseif (preg_match('/^(\w+) (tinyint|smallint|mediumint|int|bigint|double|char|varchar|varbinary)(?:\(\d+\))?( unsigned)?( NOT NULL)?(?: default \S+| auto_increment)?$/', $line, $column)) {
            $columns[] = $column[1] . ':' . $column[2] . ':' . (($column[3] ?? '') !== '' ? 'unsigned' : 'signed') . ':' . (($column[4] ?? '') !== '' ? 'NO' : 'YES');
        } else {
            throw new RuntimeException('Unrecognised alerting table definition line: ' . $line);
        }
    }
    ksort($indexes, SORT_STRING);
    return 'InnoDB|utf8mb4_unicode_ci|' . implode(',', $columns) . '|' . implode(',', array_merge(...array_values($indexes)));
}

/**
 * The same definitions as cacti.sql; a test compares the two. Times are Unix
 * seconds, so no column depends on a session time zone. alert_rule.kind is
 * threshold or baseline, and only that kind's columns are set.
 * alert_baseline_sample holds one bucket per series and wall-clock hour of
 * the installation time zone, the evaluator's history for baseline rules. alert_incident keeps
 * active_rule_id equal to rule_id while an incident is open or acknowledged
 * and NULL after it ends, so its unique key allows one active incident per
 * rule, and records the series (local_data_id, rrd_name) and the rule kind
 * the incident opened with: the series decides who may see it after its rule
 * changes, and both name it in its notification mail. upgrade_alerting_signature()
 * derives what each table must read back from this text.
 *
 * @return array<string, string>
 */
function upgrade_alerting_tables(): array
{
    return array(
        'alert_rule' => 'CREATE TABLE IF NOT EXISTS alert_rule (
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
        'alert_rule_state' => 'CREATE TABLE IF NOT EXISTS alert_rule_state (
  rule_id int(10) unsigned NOT NULL,
  incident_open tinyint(3) unsigned NOT NULL default 0,
  consecutive_breaches int(10) unsigned NOT NULL default 0,
  consecutive_oks int(10) unsigned NOT NULL default 0,
  is_unknown tinyint(3) unsigned NOT NULL default 0,
  last_observed_at int(10) unsigned default NULL,
  PRIMARY KEY (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
        'alert_baseline_sample' => 'CREATE TABLE IF NOT EXISTS alert_baseline_sample (
  local_hour int(10) unsigned NOT NULL,
  local_data_id int(10) unsigned NOT NULL,
  rrd_name varbinary(19) NOT NULL,
  sample_count int(10) unsigned NOT NULL,
  sample_sum double NOT NULL,
  sample_sum_squares double NOT NULL,
  last_observed_at int(10) unsigned NOT NULL,
  PRIMARY KEY (local_hour, local_data_id, rrd_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
        'alert_incident' => 'CREATE TABLE IF NOT EXISTS alert_incident (
  id int(10) unsigned NOT NULL auto_increment,
  rule_id int(10) unsigned NOT NULL,
  status varchar(16) NOT NULL,
  active_rule_id int(10) unsigned default NULL,
  opened_at int(10) unsigned NOT NULL,
  acknowledged_at int(10) unsigned default NULL,
  acknowledged_by mediumint(8) unsigned default NULL,
  ended_at int(10) unsigned default NULL,
  local_data_id int(10) unsigned NOT NULL,
  rrd_name varbinary(19) NOT NULL,
  rule_kind varchar(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY active_rule_id (active_rule_id),
  KEY rule_id (rule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
        'alert_notification' => 'CREATE TABLE IF NOT EXISTS alert_notification (
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
    );
}
