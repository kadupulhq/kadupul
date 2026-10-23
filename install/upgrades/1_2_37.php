<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Add the metric binding and writer ownership tables for model-driven
 * monitoring. Both are new and additive; no existing table is read or
 * changed. A table is confirmed after it is created, and any shape other
 * than the one defined here stops the upgrade before the version is
 * recorded, so a rerun starts from the same place.
 */
function upgrade_to_1_2_37(): void
{
    foreach (upgrade_model_driven_monitoring_tables() as $table => $definition) {
        db_install_execute($definition['create']);
        $found = db_install_fetch_cell(
            'SELECT CONCAT_WS(\'|\', t.ENGINE, t.TABLE_COLLATION,
                (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION) FROM information_schema.COLUMNS c
                    WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME),
                (SELECT GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) FROM information_schema.STATISTICS s
                    WHERE s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME AND s.INDEX_NAME = \'PRIMARY\'))
            FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?',
            array($table)
        );
        $expected = 'InnoDB|utf8mb4_unicode_ci|' . implode(',', $definition['columns']) . '|' . implode(',', $definition['primary']);
        if ($found['data'] !== $expected) {
            throw new RuntimeException('Table ' . $table . ' is missing or differs from the model-driven monitoring definition. Review it and rerun the upgrade; existing rows are not changed.');
        }
    }
}

/**
 * The same definitions as cacti.sql; a test compares the two. Identity
 * columns that RRDtool or a device treats as case sensitive are varbinary,
 * so the primary keys compare exact bytes whatever collation the table is
 * later converted to.
 *
 * @return array<string, array{create: string, columns: list<string>, primary: list<string>}>
 */
function upgrade_model_driven_monitoring_tables(): array
{
    return array(
        'kadupul_metric_binding' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS kadupul_metric_binding (
  device_id int(10) unsigned NOT NULL,
  metric varchar(255) NOT NULL,
  resource_kind varchar(64) NOT NULL,
  resource_identity varbinary(255) NOT NULL,
  protocol varchar(16) NOT NULL,
  pack_name varchar(64) NOT NULL,
  pack_version varchar(64) NOT NULL,
  local_data_id int(10) unsigned NOT NULL,
  data_source_name varbinary(19) NOT NULL,
  expected_type varchar(16) NOT NULL,
  status varchar(16) NOT NULL,
  origin varchar(32) NOT NULL,
  source_detail text NOT NULL,
  recorded_at datetime(6) NOT NULL,
  last_verified_at datetime(6) default NULL,
  PRIMARY KEY (device_id, metric, resource_kind, resource_identity, protocol),
  KEY storage_field (local_data_id, data_source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array('device_id', 'metric', 'resource_kind', 'resource_identity', 'protocol', 'pack_name', 'pack_version', 'local_data_id', 'data_source_name', 'expected_type', 'status', 'origin', 'source_detail', 'recorded_at', 'last_verified_at'),
            'primary' => array('device_id', 'metric', 'resource_kind', 'resource_identity', 'protocol'),
        ),
        'kadupul_writer_ownership' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS kadupul_writer_ownership (
  local_data_id int(10) unsigned NOT NULL,
  data_source_name varbinary(19) NOT NULL,
  writer varchar(32) NOT NULL,
  claimed_at datetime(6) NOT NULL,
  PRIMARY KEY (local_data_id, data_source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'columns' => array('local_data_id', 'data_source_name', 'writer', 'claimed_at'),
            'primary' => array('local_data_id', 'data_source_name'),
        ),
    );
}
