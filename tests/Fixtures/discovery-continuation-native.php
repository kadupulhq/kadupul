<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\DiscoveryContinuation;

use PDO;
use RuntimeException;

require __DIR__ . '/../Helpers/PhpSource.php';
foreach (['poller_automation.php' => ['discoverDevices'], 'lib/api_automation.php' => ['automation_update_device']] as $path => $functions) {
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    if (!is_string($source)) {
        throw new RuntimeException('Missing discovery source');
    }
    foreach ($functions as $function) {
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

const PING_SNMP = 1;
const POLLER_VERBOSITY_MEDIUM = 1;
const POLLER_VERBOSITY_HIGH = 2;
const POLLER_VERBOSITY_DEBUG = 3;

final class Net_Ping
{
    public array $host = [];
    public int $retries;
    public int $port;
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(<<<'SQL'
CREATE TABLE automation_networks (id INTEGER, name TEXT, dns_servers TEXT, enable_netbios TEXT, snmp_id INTEGER, poller_id INTEGER, site_id INTEGER, ping_retries INTEGER, ping_port INTEGER, ping_method INTEGER, add_to_cacti TEXT, same_sysname TEXT);
INSERT INTO automation_networks VALUES (1,'fixture','isolated','',1,1,0,0,0,1,'on','on');
CREATE TABLE automation_ips (network_id INTEGER, ip_address TEXT, hostname TEXT, pid INTEGER, thread INTEGER, status INTEGER);
INSERT INTO automation_ips VALUES (1,'192.0.2.1','',0,0,0),(1,'192.0.2.2','',0,0,0);
CREATE TABLE host (id INTEGER PRIMARY KEY, hostname TEXT, snmp_sysName TEXT, status INTEGER, snmp_version INTEGER, deleted TEXT);
CREATE TABLE graph_templates (id INTEGER PRIMARY KEY);
INSERT INTO graph_templates VALUES (5);
CREATE TABLE host_graph (host_id INTEGER, graph_template_id INTEGER);
CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, graph_template_id INTEGER);
CREATE TABLE snmp_query (id INTEGER);
CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, reindex_method INTEGER);
SQL);
$templates = [];
$trees = [];
$logs = [];
$mode = $argv[1] ?? 'failed-first-device';
function db_fetch_row_prepared(string $sql, array $parameters): array
{
    $rows = db_fetch_assoc_prepared($sql, $parameters);
    return $rows[0] ?? [];
}
function db_fetch_assoc_prepared(string $sql, array $parameters): array
{
    global $db;
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc(string $sql): array
{
    if (str_contains($sql, 'FROM automation_templates')) {
        return [];
    }
    return db_fetch_assoc_prepared($sql, []);
}
function db_fetch_cell_prepared(string $sql, array $parameters): mixed
{
    global $db;
    if (str_contains($sql, 'FROM automation_processes')) {
        return 'run';
    }
    $query = $db->prepare($sql);
    $query->execute($parameters);
    return $query->fetchColumn();
}
function db_execute_prepared(string $sql, array $parameters): bool
{
    global $db;
    // SQLite lacks the production UPDATE LIMIT grammar. Preserve the same
    // single unclaimed-IP selection; this is not a database-engine contract.
    if (str_contains($sql, 'UPDATE automation_ips') && str_contains($sql, 'LIMIT 1')) {
        $sql = 'UPDATE automation_ips SET pid=?,thread=? WHERE network_id=? AND status=0 AND pid=0 AND ip_address=(SELECT ip_address FROM automation_ips WHERE network_id=? AND status=0 AND pid=0 LIMIT 1)';
        $parameters[] = $parameters[2];
    }
    return $db->prepare($sql)->execute($parameters);
}
function cacti_sizeof(mixed $value): int
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(string $message, ...$arguments): void
{
    global $logs;
    $logs[] = $message;
}
function automation_get_pid(): string
{
    return 'fixture';
}
function automation_function_with_pid(string $name): string
{
    return $name;
}
function automation_debug(string $message): void {}
function automation_get_dns_from_ip(string $ip, string $dns, int $timeout): string
{
    return $ip;
}
function gethostbyaddr(string $ip): string
{
    return $ip;
}
function is_ipaddress(string $value): bool
{
    return filter_var($value, FILTER_VALIDATE_IP) !== false;
}
function markIPRunning(string $ip, int $network): void {}
function addSNMPDevice(int $network, int $pid): void {}
function automation_valid_snmp_device(array &$device): bool
{
    $device['snmp_sysName'] = 'fixture-' . substr($device['ip_address'], -1);
    $device['snmp_sysDescr'] = 'isolated fixture';
    $device['snmp_sysObjectID'] = 'fixture';
    return true;
}
function automation_find_os(...$arguments): array
{
    return ['name' => 'fixture', 'host_template' => 1, 'availability_method' => 1];
}
function automation_add_device(array $device): int
{
    global $db;
    $id = 6 + (int) substr($device['ip_address'], -1);
    db_execute_prepared("INSERT INTO host VALUES (?, ?, ?, 3, 2, '')", [$id, $device['ip_address'], $device['snmp_sysName']]);
    db_execute_prepared('INSERT INTO host_graph VALUES (?,5)', [$id]);
    return $id;
}
function update_discovered_host_fields(int $id, array $device): void {}
function automation_graph_automation_eligible(int $template): bool
{
    return true;
}
function automation_execute_graph_template(int $host, int $template): bool
{
    global $templates, $mode;
    $templates[] = [$host, $template];
    if ($host === 7 && $mode === 'failed-first-device') {
        return false;
    }
    db_execute_prepared('INSERT INTO graph_local (host_id,graph_template_id) VALUES (?,?)', [$host, $template]);
    return true;
}
function automation_execute_device_create_tree(int $host): bool
{
    global $trees;
    $trees[] = $host;
    return true;
}
function markIPDone(string $ip, int $network): void
{
    db_execute_prepared('UPDATE automation_ips SET status=1 WHERE ip_address=? AND network_id=?', [$ip, $network]);
}

$result = discoverDevices(1, 1);
echo json_encode(['result' => $result, 'templates' => $templates, 'trees' => $trees, 'hosts' => $db->query('SELECT id FROM host ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'graphs' => $db->query('SELECT host_id FROM graph_local ORDER BY host_id')->fetchAll(PDO::FETCH_COLUMN), 'done' => $db->query('SELECT status FROM automation_ips ORDER BY ip_address')->fetchAll(PDO::FETCH_COLUMN), 'finished' => str_contains(end($logs), 'Thread 1 Finished')], JSON_THROW_ON_ERROR);
