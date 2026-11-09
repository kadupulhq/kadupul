<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Scope Boost partition status to a run and add the Messenger transport
 * table used by the optional queue mode. The status table is a MEMORY table
 * whose rows only live for one Boost run, so it is emptied before it is
 * altered. The queue table is new. Each table is confirmed after it is
 * created or altered, and any shape other than the one defined here stops
 * the upgrade before the version is recorded, so a rerun starts from the
 * same place.
 */
function upgrade_to_1_2_38(): void
{
    // cacti.sql has created this Boost work list since 1.2.19, but no upgrade
    // did, and Boost only creates it on its first run. The installer requires
    // every cacti.sql table (#1088), so an older install that never ran Boost
    // gets it here. An existing table, whatever its shape, is left alone.
    db_install_execute('CREATE TABLE IF NOT EXISTS `poller_output_boost_local_data_ids` (
        `local_data_id` int(10) unsigned NOT NULL DEFAULT 0,
        `process_handler` int(10) unsigned DEFAULT 0,
        PRIMARY KEY (`local_data_id`),
        KEY `process_handler` (`process_handler`)
    ) ENGINE=MEMORY');

    foreach (upgrade_boost_queue_tables() as $table => $definition) {
        db_install_execute($definition['create']);

        if ($table === 'poller_output_boost_processes') {
            upgrade_boost_scope_status_table($table);
        }

        $found = db_install_fetch_cell(
            'SELECT CONCAT_WS(\'|\', t.ENGINE, IF(? = 1, t.TABLE_COLLATION, \'\'),
                (SELECT GROUP_CONCAT(c.COLUMN_NAME ORDER BY c.ORDINAL_POSITION) FROM information_schema.COLUMNS c
                    WHERE c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME),
                (SELECT GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX) FROM information_schema.STATISTICS s
                    WHERE s.TABLE_SCHEMA = t.TABLE_SCHEMA AND s.TABLE_NAME = t.TABLE_NAME AND s.INDEX_NAME = ? AND s.NON_UNIQUE = ?))
            FROM information_schema.TABLES t WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ?',
            array($definition['collation'] === null ? 0 : 1, $definition['index'], $definition['non_unique'], $table)
        );
        $expected = $definition['engine'] . '|' . ($definition['collation'] ?? '') . '|' . implode(',', $definition['columns']) . '|' . implode(',', $definition['index_columns']);
        if ($found['data'] !== $expected) {
            throw new RuntimeException('Table ' . $table . ' is missing or differs from the Boost queue definition. Review it and rerun the upgrade; existing rows are not changed.');
        }

        if ($table === 'poller_output_boost_processes') {
            $run_id = db_install_fetch_cell(
                'SELECT CONCAT_WS(\'|\', COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, \'NULL\')) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                array($table, 'run_id')
            );
            if ($run_id['data'] !== 'char(32)|YES|NULL') {
                throw new RuntimeException('Table ' . $table . ' is missing or differs from the Boost queue definition. Review it and rerun the upgrade; existing rows are not changed.');
            }
        }
    }

    upgrade_widen_account_id_columns();
}

/**
 * Add the run and partition columns and their unique key to a status table
 * created before 1.2.38. Each piece is added only when missing, so a rerun
 * after a partial failure completes the change.
 */
function upgrade_boost_scope_status_table(string $table): void
{
    $missing = array();
    foreach (array('run_id', 'process_handler') as $column) {
        $present = db_install_fetch_cell(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, $column)
        );
        if ($present['data'] === false) {
            return;
        }
        if ((int) $present['data'] === 0) {
            $missing[] = $column;
        }
    }

    $key_missing = !db_index_exists($table, 'run_partition');
    if ($missing !== array() || $key_missing) {
        // Rows belong to a finished run, and default rows from an earlier
        // attempt that stopped before the key was added would block it.
        db_install_execute('TRUNCATE TABLE ' . $table);
    }
    // run_id is nullable so a 1.2.37 child after a rollback, which inserts only
    // its status, adds NULL run ids; a unique key never matches two NULLs.
    if (in_array('run_id', $missing, true)) {
        db_install_execute('ALTER TABLE ' . $table . ' ADD COLUMN run_id char(32) NULL DEFAULT NULL AFTER sock_int_value');
    } else {
        $run_id = db_install_fetch_cell(
            'SELECT CONCAT_WS(\'|\', COLUMN_TYPE, IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, 'run_id')
        );
        // An earlier attempt of this step added it NOT NULL. Any other shape is
        // left for the check in upgrade_to_1_2_38() to refuse.
        if ($run_id['data'] === 'char(32)|NO') {
            db_install_execute('ALTER TABLE ' . $table . ' MODIFY run_id char(32) NULL DEFAULT NULL');
        }
    }
    if (in_array('process_handler', $missing, true)) {
        db_install_execute('ALTER TABLE ' . $table . ' ADD COLUMN process_handler int(10) unsigned NOT NULL DEFAULT 0 AFTER run_id');
    }
    if ($key_missing) {
        db_install_execute('ALTER TABLE ' . $table . ' ADD UNIQUE KEY run_partition (run_id, process_handler)');
    }
}

/**
 * The same definitions as cacti.sql; a test compares the two. The queue
 * table is the one symfony/doctrine-messenger builds for the table name
 * kadupul_messenger_messages, including its generated index name, so the
 * transport finds nothing to change when it checks the schema.
 *
 * @return array<string, array{create: string, engine: string, collation: string|null, columns: list<string>, index: string, non_unique: int, index_columns: list<string>}>
 */
function upgrade_boost_queue_tables(): array
{
    return array(
        'poller_output_boost_processes' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS poller_output_boost_processes (
  sock_int_value bigint(20) unsigned NOT NULL auto_increment,
  run_id char(32) default NULL,
  process_handler int(10) unsigned NOT NULL default 0,
  status varchar(255) default NULL,
  PRIMARY KEY (sock_int_value),
  UNIQUE KEY run_partition (run_id, process_handler)
) ENGINE=MEMORY',
            'engine' => 'MEMORY',
            'collation' => null,
            'columns' => array('sock_int_value', 'run_id', 'process_handler', 'status'),
            'index' => 'run_partition',
            'non_unique' => 0,
            'index_columns' => array('run_id', 'process_handler'),
        ),
        'kadupul_messenger_messages' => array(
            'create' => 'CREATE TABLE IF NOT EXISTS kadupul_messenger_messages (
  id bigint NOT NULL auto_increment,
  body longtext NOT NULL,
  headers longtext NOT NULL,
  queue_name varchar(190) NOT NULL,
  created_at datetime NOT NULL,
  available_at datetime NOT NULL,
  delivered_at datetime default NULL,
  PRIMARY KEY (id),
  KEY IDX_7CB5BDEEFB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=Dynamic',
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
            'columns' => array('id', 'body', 'headers', 'queue_name', 'created_at', 'available_at', 'delivered_at'),
            'index' => 'IDX_7CB5BDEEFB7336F0E3BD61CE16BA31DBBF396750',
            'non_unique' => 1,
            'index_columns' => array('queue_name', 'available_at', 'delivered_at', 'id'),
        ),
    );
}

/**
 * Give the account and group id columns the type of the id they hold (#927).
 * user_log.user_id and user_auth_row_cache.user_id were signed mediumint, so
 * an account id above 8388607 was stored as 8388607, and
 * settings_user_group.group_id was smallint and user_auth_group_perms.group_id
 * was mediumint, so a group id above their range was stored as the largest
 * value they hold. A column that is already as wide is left alone, so a rerun
 * changes nothing, and a missing table is skipped. A failed read or a negative
 * id, which the upgrade session would clamp to 0, stops the upgrade before the
 * version is recorded.
 */
function upgrade_widen_account_id_columns(): void
{
    $ranks = array('tinyint' => 1, 'smallint' => 2, 'mediumint' => 3, 'int' => 4, 'bigint' => 5);
    $columns = array(
        array('user_log', 'user_id', 'mediumint', "mediumint(8) unsigned NOT NULL default '0'"),
        array('user_auth_row_cache', 'user_id', 'mediumint', "mediumint(8) unsigned NOT NULL default '0'"),
        array('settings_user_group', 'group_id', 'int', "int(10) unsigned NOT NULL default '0'"),
        array('user_auth_group_perms', 'group_id', 'int', "int(10) unsigned NOT NULL default '0'"),
    );

    foreach ($columns as list($table, $column, $type, $definition)) {
        $current = db_install_fetch_cell(
            'SELECT CONCAT_WS(\'|\', DATA_TYPE, COLUMN_TYPE) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            array($table, $column)
        );
        if ($current['status'] === DB_STATUS_ERROR) {
            throw new RuntimeException('The type of ' . $table . '.' . $column . ' could not be read. Rerun the upgrade.');
        }
        if (!is_string($current['data']) || $current['data'] === '') {
            continue;
        }

        list($data_type, $column_type) = array_pad(explode('|', strtolower($current['data']), 2), 2, '');
        $rank = $ranks[$data_type] ?? 0;
        if ($rank > $ranks[$type] || ($rank === $ranks[$type] && str_contains($column_type, 'unsigned'))) {
            continue;
        }

        $negative = db_install_fetch_cell('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` < 0');
        if ($negative['status'] === DB_STATUS_ERROR) {
            throw new RuntimeException('Table ' . $table . ' could not be read. Rerun the upgrade.');
        }
        if ((int) $negative['data'] > 0) {
            throw new RuntimeException('Column ' . $table . '.' . $column . ' holds negative ids, which widening would change to 0. Review them and rerun the upgrade.');
        }

        if (db_install_execute('ALTER TABLE `' . $table . '` MODIFY `' . $column . '` ' . $definition) === DB_STATUS_ERROR) {
            throw new RuntimeException('Column ' . $table . '.' . $column . ' could not be widened. Rerun the upgrade.');
        }
    }
}
