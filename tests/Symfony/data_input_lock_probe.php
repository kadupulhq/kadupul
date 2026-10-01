<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Run only against the disposable database used by the database-contract matrix.
// Execute the native schema and upgrade, then observe locks on two connections.
$host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$port = getenv('BOOST_DB_PORT') ?: '3306';
$database = getenv('BOOST_DB_NAME');
if (!$database) {
    throw new RuntimeException('BOOST_DB_NAME must identify a disposable database.');
}
$dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
$connect = static fn() => new PDO($dsn, getenv('BOOST_DB_USER'), getenv('BOOST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$owner = $connect();
$writer = $connect();
$writer->exec('SET SESSION innodb_lock_wait_timeout=1');
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/cacti.sql');
$created = [];
$upgraded = false;
function db_install_execute($sql)
{
    return $GLOBALS['owner']->exec($sql);
}
function db_index_exists($table, $name)
{
    $statement = $GLOBALS['owner']->prepare("SHOW INDEX FROM `$table` WHERE Key_name=?");
    $statement->execute([$name]);
    return $statement->fetch() !== false;
}
require $root . '/install/upgrades/1_2_31.php';
$tables = ['data_template_rrd', 'data_input_fields', 'automation_devices', 'automation_snmp_items', 'snmpagent_managers', 'settings', 'settings_user', 'snmp_query_graph', 'user_auth_row_cache'];
foreach ([...$tables, 'poller_output_rejected'] as $table) {
    $check = $owner->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $check->execute([$table]);
    if ((int) $check->fetchColumn() !== 0) {
        throw new RuntimeException('Fixture refuses to replace existing table: ' . $table);
    }
}
try {
    $baseline = file_get_contents($root . '/docs/audit_schema.sql');
    foreach (['table_columns', 'table_indexes'] as $table) {
        preg_match('/CREATE TABLE `' . $table . '` \(.*?;\s/s', $baseline, $definition);
        $owner->exec(str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $definition[0]));
        preg_match_all('/INSERT INTO `' . $table . '` VALUES \(\'data_template_rrd\'.*?;/', $baseline, $rows);
        foreach ($rows[0] as $row) {
            $owner->exec($row);
        }
    }
    foreach ($tables as $table) {
        if (!preg_match('/CREATE TABLE `?' . $table . '`? \(.*?;\s/s', $source, $match)) {
            throw new RuntimeException('Native fixture schema unavailable: ' . $table);
        }
        $owner->exec($match[0]);
        $created[] = $table;
    }
    for ($id = 1; $id <= 1000; ++$id) {
        $owner->exec("INSERT INTO data_input_fields (id,data_input_id) VALUES ($id," . intdiv($id, 10) . ')');
    }
    for ($first = 1; $first <= 10000; $first += 1000) {
        $rows = [];
        for ($id = $first; $id < $first + 1000; ++$id) {
            $rows[] = "($id,$id," . (intdiv($id - 1, 10) + 1) . ",'fixture')";
        }
        $owner->exec('INSERT INTO data_template_rrd (id,local_data_id,data_input_field_id,data_source_name) VALUES ' . implode(',', $rows));
    }
    foreach (['fresh', 'upgrade'] as $mode) {
        if ($mode === 'upgrade') {
            $owner->exec('ALTER TABLE data_template_rrd DROP INDEX data_input_field_id');
            $upgraded = true;
            upgrade_to_1_2_31();
            upgrade_to_1_2_31(); // An already upgraded installation must remain valid.
        }
        $owner->query('ANALYZE TABLE data_template_rrd,data_input_fields')->fetchAll();
        $actual = $owner->query("SHOW INDEX FROM data_template_rrd WHERE Key_name='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
        $expected = $owner->query("SELECT * FROM table_indexes WHERE idx_key_name='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
        foreach (['Non_unique' => 'idx_non_unique', 'Seq_in_index' => 'idx_seq_in_index', 'Column_name' => 'idx_column_name', 'Index_type' => 'idx_index_type'] as $live => $audit) {
            if (!$expected || (string) $actual[$live] !== (string) $expected[$audit]) {
                throw new RuntimeException('RRD field index differs from the native audit baseline.');
            }
        }
        $column = $owner->query("SHOW COLUMNS FROM data_template_rrd WHERE Field='data_input_field_id'")->fetch(PDO::FETCH_ASSOC);
        if ($column['Key'] !== $owner->query("SELECT table_key FROM table_columns WHERE table_field='data_input_field_id'")->fetchColumn()) {
            throw new RuntimeException('RRD field column differs from the native audit baseline.');
        }
        foreach (['SELECT id FROM data_template_rrd WHERE data_input_field_id=100 FOR UPDATE', 'SELECT r.id FROM data_template_rrd r INNER JOIN data_input_fields f ON f.id=r.data_input_field_id WHERE f.data_input_id=10 FOR UPDATE'] as $query) {
            $plan = $owner->query('EXPLAIN ' . $query)->fetchAll(PDO::FETCH_ASSOC);
            $rrd = array_values(array_filter($plan, static fn(array $row): bool => in_array($row['table'], ['data_template_rrd', 'r'], true)))[0];
            if ($rrd['key'] !== 'data_input_field_id') {
                throw new RuntimeException($mode . ': RRD reference lookup does not use the field index.');
            }
            $owner->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $owner->beginTransaction();
            $owner->query($query)->fetchAll();
            $writer->beginTransaction();
            $writer->exec("UPDATE data_template_rrd SET data_source_name='unrelated' WHERE id=9000");
            $writer->rollBack();
            $writer->beginTransaction();
            $blocked = false;
            try {
                $writer->exec("UPDATE data_template_rrd SET data_source_name='related' WHERE id=991");
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1205) {
                    throw $error;
                }
                $blocked = true;
            } finally {
                $writer->rollBack();
            }
            if (!$blocked) {
                throw new RuntimeException($mode . ': selected RRD reference was not locked.');
            }
            $owner->rollBack();
        }
        echo 'PASS: ' . $mode . " indexed field/method checks permit unrelated writes and retain selected-row locks.\n";
    }
} finally {
    foreach ([$owner, $writer] as $db) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }
    foreach (array_reverse($created) as $table) {
        $owner->exec("DROP TABLE `$table`");
    }
    if ($upgraded) {
        $owner->exec('DROP TABLE IF EXISTS poller_output_rejected');
    }
}
