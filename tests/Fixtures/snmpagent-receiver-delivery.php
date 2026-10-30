<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Stand-ins cover only the external trap process and standard legacy I/O adapters.
// Both production selection queries and notification-log INSERTs run on PDO.
const SNMPAGENT_EVENT_SEVERITY_LOW = 1;
const SNMPAGENT_EVENT_SEVERITY_MEDIUM = 2;
const SNMPAGENT_EVENT_SEVERITY_HIGH = 3;
const SNMPAGENT_EVENT_SEVERITY_CRITICAL = 4;
const POLLER_VERBOSITY_NONE = 1;
const POLLER_VERBOSITY_MEDIUM = 3;

function receiver_rows(): array
{
    $base = [
        'snmp_version' => 2, 'snmp_community' => 'public', 'snmp_port' => 162,
        'snmp_message_type' => 1, 'snmp_engine_id' => '', 'snmp_username' => '',
        'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '',
        'snmp_priv_protocol' => '',
    ];

    return [
        $base + ['id' => 1, 'hostname' => 'enabled.example', 'disabled' => ''],
        $base + ['id' => 2, 'hostname' => 'disabled.example', 'disabled' => 'on'],
        $base + ['id' => 3, 'hostname' => 'legacy-null.example', 'disabled' => null],
    ];
}

function read_config_option($name)
{
    return '/usr/bin/snmptrap';
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {}

function cacti_sizeof($value)
{
    return is_countable($value) ? count($value) : 0;
}

function cacti_escapeshellarg($arg)
{
    return escapeshellarg((string) $arg);
}

function cacti_escapeshellcmd($command)
{
    return escapeshellcmd($command);
}

function cacti_is_sensitive_key($key)
{
    return in_array($key, ['snmp_community', 'snmp_password', 'snmp_priv_passphrase'], true);
}

function sql_save($save, $table)
{
    $values = $save;
    unset($values['id']);
    $db = $GLOBALS['snmpagent_disabled_database'];
    $query = $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', array_keys($values)) . ') VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
    $query->execute(array_values($values));
    return (int) $db->lastInsertId();
}

function db_fetch_cell_prepared($sql, $params = [])
{
    $query = $GLOBALS['snmpagent_disabled_database']->prepare($sql);
    $query->execute($params);
    return $query->fetchColumn();
}

function db_fetch_assoc_prepared($sql, $params = [])
{
    $query = $GLOBALS['snmpagent_disabled_database']->prepare($sql);
    $query->execute($params);
    $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
    if (($GLOBALS['snmpagent_disable_after_discovery'] ?? false) && str_contains($sql, 'SELECT snmpagent_managers.*')) {
        $GLOBALS['snmpagent_disabled_database']->exec("UPDATE snmpagent_managers SET disabled = 'on' WHERE id = 1");
    }
    return $rows;
}

function exec_background($filename, $args = '', $redirect_args = '')
{
    $GLOBALS['snmpagent_disabled_traps'][] = $args;
}

function receiverFixtureSetup(): void
{
    global $config, $snmpagent_event_severity;
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    $password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD');
    if ($password === false) {
        $password = getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '';
    }
    $db = new \PDO($dsn ?: 'sqlite::memory:', getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $GLOBALS['snmpagent_disabled_database'] = $db;
    $GLOBALS['snmpagent_disabled_schema'] = null;
    if ($dsn) {
        $schema = 'receiver_delivery_' . bin2hex(random_bytes(8));
        $db->exec('CREATE DATABASE `' . $schema . '` CHARACTER SET utf8mb4');
        $GLOBALS['snmpagent_disabled_schema'] = $schema;
        $db->exec('USE `' . $schema . '`');
        $source = file_get_contents(dirname(__DIR__, 2) . '/cacti.sql');
        foreach (['snmpagent_managers', 'snmpagent_managers_notifications', 'snmpagent_notifications_log', 'snmpagent_cache', 'snmpagent_cache_notifications', 'snmpagent_cache_textual_conventions'] as $table) {
            if (!is_string($source) || preg_match('/CREATE TABLE `' . $table . '` \(.*?\) ENGINE=.*?;/s', $source, $match) !== 1) {
                throw new \RuntimeException('Actual notification schema unavailable.');
            }
            $db->exec($match[0]);
        }
    } else {
        $db->exec('CREATE TABLE snmpagent_managers (id INTEGER PRIMARY KEY,hostname TEXT,disabled CHAR(2),snmp_version INTEGER,snmp_community TEXT,snmp_port INTEGER,snmp_message_type INTEGER,snmp_engine_id TEXT,snmp_username TEXT,snmp_password TEXT,snmp_auth_protocol TEXT,snmp_priv_passphrase TEXT,snmp_priv_protocol TEXT)');
        $db->exec('CREATE TABLE snmpagent_managers_notifications (manager_id INTEGER,notification TEXT,mib TEXT)');
        $db->exec('CREATE TABLE snmpagent_notifications_log (id INTEGER PRIMARY KEY,time INTEGER,severity INTEGER,manager_id INTEGER,notification TEXT,mib TEXT,varbinds TEXT)');
        $db->exec('CREATE TABLE snmpagent_cache (oid TEXT,name TEXT,mib TEXT,type TEXT)');
        $db->exec('CREATE TABLE snmpagent_cache_notifications (name TEXT,mib TEXT,attribute TEXT,sequence_id INTEGER)');
        $db->exec('CREATE TABLE snmpagent_cache_textual_conventions (name TEXT,mib TEXT,type TEXT)');
    }
    foreach (receiver_rows() as $row) {
        if ($dsn) {
            $row += ['description' => $row['hostname'], 'max_log_size' => 31];
        }
        $query = $db->prepare('INSERT INTO snmpagent_managers (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')');
        $query->execute(array_values($row));
        $query = $db->prepare('INSERT INTO snmpagent_managers_notifications (manager_id,notification,mib) VALUES (?,?,?)');
        $query->execute([$row['id'], 'cactiNotifyDeviceDown', 'CACTI-MIB']);
    }
    $db->exec("INSERT INTO snmpagent_cache (oid,name,mib) VALUES ('1.3.6.1.4.1.23925.1.2.1','cactiNotifyDeviceDown','CACTI-MIB')");
    $config['library_path'] = sys_get_temp_dir() . '/receiver-delivery-' . bin2hex(random_bytes(8));
    if (!mkdir($config['library_path'], 0700) || file_put_contents($config['library_path'] . '/poller.php', "<?php\n") !== 6) {
        throw new \RuntimeException('Owned sender fixture could not be created.');
    }
    unset($config['snmpagent']);
    $snmpagent_event_severity = [1 => 'LOW', 2 => 'MEDIUM', 3 => 'HIGH', 4 => 'CRITICAL'];
    $GLOBALS['snmpagent_disabled_traps'] = [];
}

function receiverFixtureCleanup(): void
{
    global $config;
    $schema = $GLOBALS['snmpagent_disabled_schema'] ?? null;
    if (is_string($schema)) {
        $GLOBALS['snmpagent_disabled_database']->exec('DROP DATABASE `' . $schema . '`');
    }
    if (isset($config['library_path'])) {
        unlink($config['library_path'] . '/poller.php');
        rmdir($config['library_path']);
    }
}

require dirname(__DIR__, 2) . '/lib/snmpagent.php';
$scenario = $argv[1] ?? 'mixed';
try {
    receiverFixtureSetup();
    if ($scenario === 'all-disabled') {
        $GLOBALS['snmpagent_disabled_database']->exec("UPDATE snmpagent_managers SET disabled = 'on'");
    } elseif ($scenario === 'disabled-after-discovery') {
        $GLOBALS['snmpagent_disabled_database']->exec("UPDATE snmpagent_managers SET disabled = 'on' WHERE id <> 1");
        $GLOBALS['snmpagent_disable_after_discovery'] = true;
    } elseif ($scenario !== 'mixed') {
        throw new RuntimeException('Unknown receiver fixture scenario.');
    }
    $returned = snmpagent_notification('cactiNotifyDeviceDown', 'CACTI-MIB', [], SNMPAGENT_EVENT_SEVERITY_HIGH);
    $logs = array_map('intval', $GLOBALS['snmpagent_disabled_database']->query('SELECT manager_id FROM snmpagent_notifications_log ORDER BY manager_id')->fetchAll(PDO::FETCH_COLUMN));
    $result = ['returned' => $returned, 'traps' => $GLOBALS['snmpagent_disabled_traps'], 'logs' => $logs];
    $GLOBALS['nativeChildCoverageMarkers'][] = 'receiver-delivery-confirmed';
    echo json_encode($result, JSON_THROW_ON_ERROR);
} finally {
    receiverFixtureCleanup();
}
