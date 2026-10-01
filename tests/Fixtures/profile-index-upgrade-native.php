<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$directory = $argv[2];
$source = $root . '/install/upgrades/1_2_34.php';
$copy = $directory . '/install/upgrades/1_2_34.php';
mkdir(dirname($copy), 0700, true);
mkdir($directory . '/lib', 0700);
copy($root . '/lib/data_source_profile_integrity.php', $directory . '/lib/data_source_profile_integrity.php');
copy($source, $copy);
if (isset($argv[3])) {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $copy);
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $source);
    require __DIR__ . '/rrd-process-coverage.php';
}
$mysql = getenv('PROFILE_DELETE_MYSQL') === '1';
$db = $mysql ? new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '') : new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = $mysql ? 'CREATE TEMPORARY TABLE ' : 'CREATE TABLE ';
foreach (array(
    'data_template_data' => 'id INTEGER PRIMARY KEY, data_source_profile_id INTEGER',
    'automation_devices' => 'snmp_priv_protocol VARCHAR(6)',
    'automation_snmp_items' => 'snmp_priv_protocol VARCHAR(6)',
    'snmpagent_managers' => 'snmp_priv_protocol VARCHAR(6)',
    'settings' => 'name VARCHAR(64)',
    'settings_user' => 'name VARCHAR(64)',
    'snmp_query_graph' => 'snmp_query_id INTEGER, graph_template_id INTEGER',
    'user_auth_row_cache' => 'class VARCHAR(64), time INTEGER',
) as $table => $columns) {
    if ($mysql && $table === 'data_template_data') {
        if (!preg_match('/CREATE TABLE data_template_data \(.*?\) ENGINE=.*?;/s', file_get_contents($root . '/cacti.sql'), $definition)) {
            throw new RuntimeException('Fresh profile reference schema unavailable');
        }
        $db->exec(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $definition[0]));
        $db->exec('ALTER TABLE data_template_data DROP INDEX data_source_profile_id');
    } else {
        $db->exec($prefix . $table . ' (' . $columns . ')');
    }
}
$calls = array();
$guards = array();
if (isset($scenario['index_failure']) && $scenario['index_failure'] !== 'create-failure') {
    $column = $scenario['index_failure'] === 'wrong-column' ? 'id' : 'data_source_profile_id';
    $unique = $scenario['index_failure'] === 'unique' ? 'UNIQUE ' : '';
    $db->exec('CREATE ' . $unique . 'INDEX data_source_profile_id ON data_template_data (' . $column . ')');
    if ($scenario['index_failure'] === 'hidden' && $mysql) {
        $version = $db->query('SELECT VERSION()')->fetchColumn();
        $db->exec('ALTER TABLE data_template_data ALTER INDEX data_source_profile_id ' . (str_contains($version, 'MariaDB') ? 'IGNORED' : 'INVISIBLE'));
    }
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    if (str_contains($sql, 'information_schema.STATISTICS')) {
        // Temporary MySQL tables have SHOW INDEX metadata but no STATISTICS rows.
        if ($GLOBALS['mysql']) {
            $rows = $GLOBALS['db']->query('SHOW INDEX FROM data_template_data')->fetchAll(PDO::FETCH_ASSOC);
            return array_values(array_map(static fn($row) => array_merge($row, ['SEQ_IN_INDEX' => $row['Seq_in_index'], 'COLUMN_NAME' => $row['Column_name'], 'SUB_PART' => $row['Sub_part'], 'NON_UNIQUE' => $row['Non_unique'], 'IS_VISIBLE' => $row['Visible'] ?? 'YES', 'IGNORED' => $row['Ignored'] ?? 'NO']), array_filter($rows, static fn($row) => $row['Key_name'] === 'data_source_profile_id' && (int) $row['Seq_in_index'] === 1)));
        }
        $rows = $GLOBALS['db']->query('PRAGMA index_list(data_template_data)')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($row['name'] === 'data_source_profile_id') {
                $columns = $GLOBALS['db']->query('PRAGMA index_info(data_source_profile_id)')->fetchAll(PDO::FETCH_ASSOC);
                return [['SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => $columns[0]['name'], 'SUB_PART' => null, 'NON_UNIQUE' => $row['unique'] ? 0 : 1, 'IS_VISIBLE' => ($GLOBALS['scenario']['index_failure'] ?? '') === 'hidden' ? 'NO' : 'YES']];
            }
        }
        return [];
    }
    if (str_contains($sql, 'information_schema.TABLES')) {
        return array(array('TABLE_NAME' => 'data_source_profiles_rra', 'ENGINE' => 'InnoDB'), array('TABLE_NAME' => 'data_source_profiles_cf', 'ENGINE' => 'InnoDB'), array('TABLE_NAME' => 'data_source_profiles', 'ENGINE' => 'InnoDB'), array('TABLE_NAME' => 'data_template_data', 'ENGINE' => 'InnoDB'));
    }
    if (count($params) === 1) {
        return isset($GLOBALS['guards'][$params[0]]) ? array(array('TRIGGER_NAME' => $params[0])) : array();
    }
    $rows = array();
    foreach ($GLOBALS['guards'] as $name => $definition) {
        $rows[] = array('TRIGGER_NAME' => $name, 'EVENT_OBJECT_TABLE' => $definition['table'], 'ACTION_TIMING' => $definition['timing'], 'EVENT_MANIPULATION' => $definition['event'], 'ACTION_STATEMENT' => $definition['body']);
    }
    return $rows;
}
function db_index_exists($table, $index)
{
    $rows = $GLOBALS['db']->query($GLOBALS['mysql'] ? 'SHOW INDEX FROM ' . $table : 'PRAGMA index_list(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC);
    return in_array($index, array_column($rows, $GLOBALS['mysql'] ? 'Key_name' : 'name'), true);
}
function db_install_execute($sql)
{
    $GLOBALS['calls'][] = $sql;
    if (($GLOBALS['scenario']['index_failure'] ?? '') === 'create-failure' && $sql === 'ALTER TABLE data_template_data ADD INDEX data_source_profile_id (data_source_profile_id)') {
        return false;
    }
    foreach (array_merge(data_source_profile_reference_triggers(), data_source_profile_definition_triggers()) as $name => $definition) {
        if ($sql === $definition['sql']) {
            if (($GLOBALS['scenario']['guard_failure'] ?? false)) {
                return false;
            }
            $GLOBALS['guards'][$name] = $definition;
            return true;
        }
    }
    if (!$GLOBALS['mysql']) {
        // SQLite can verify the index migration; unrelated MySQL DDL is isolated.
        if (!preg_match('/^ALTER TABLE (\w+) ADD INDEX (\w+) (\(.+\))$/', $sql, $match)) {
            return true;
        }
        $sql = 'CREATE INDEX ' . $match[2] . ' ON ' . $match[1] . ' ' . $match[3];
    } else {
        // Keep all migration objects owned by this connection.
        $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql);
    }
    $GLOBALS['db']->exec($sql);
    return true;
}
require $copy;
$upgradeError = null;
try {
    upgrade_to_1_2_34();
    upgrade_to_1_2_34();
} catch (RuntimeException $error) {
    $upgradeError = $error->getMessage();
}
if ($upgradeError !== null) {
    file_put_contents($directory . '/result.json', json_encode(array('upgrade_error' => $upgradeError), JSON_THROW_ON_ERROR));
    exit;
}
$indexes = $db->query($mysql ? 'SHOW INDEX FROM data_template_data' : 'PRAGMA index_list(data_template_data)')->fetchAll(PDO::FETCH_ASSOC);
$audit = array();
if ($mysql) {
    $baseline = file_get_contents($root . '/docs/audit_schema.sql');
    foreach (array('table_columns', 'table_indexes') as $table) {
        if (!preg_match('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=.*?;/s', $baseline, $definition)) {
            throw new RuntimeException('Audit baseline table unavailable');
        }
        $db->exec(str_replace('CREATE TABLE ', 'CREATE TEMPORARY TABLE ', $definition[0]));
        preg_match_all('/^INSERT INTO `' . $table . '` VALUES \(\x27data_template_data\x27,.*?;$/m', $baseline, $records);
        foreach ($records[0] as $record) {
            $db->exec($record);
        }
    }
    $columns = $db->query('SHOW FULL COLUMNS FROM data_template_data')->fetchAll(PDO::FETCH_ASSOC);
    $column = array_values(array_filter($columns, static fn($row) => $row['Field'] === 'data_source_profile_id'))[0];
    $audit['liveKey'] = $column['Key'];
    $audit['baselineKey'] = $db->query("SELECT table_key FROM table_columns WHERE table_name='data_template_data' AND table_field='data_source_profile_id'")->fetchColumn();
    // These are the native audit workflow's index identity predicates.
    $query = $db->prepare('SELECT COUNT(*) FROM table_indexes WHERE idx_table_name=? AND idx_key_name=? AND idx_seq_in_index=? AND idx_column_name=?');
    $query->execute(array('data_template_data', 'data_source_profile_id', 1, 'data_source_profile_id'));
    $audit['recognized'] = (int) $query->fetchColumn();
    $audit['index'] = array_values(array_filter($indexes, static fn($row) => $row['Key_name'] === 'data_source_profile_id'))[0];
}
file_put_contents($directory . '/result.json', json_encode(array('calls' => $calls, 'runs' => 2, 'indexes' => array_column($indexes, $mysql ? 'Key_name' : 'name'), 'audit' => $audit), JSON_THROW_ON_ERROR));
