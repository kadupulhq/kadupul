<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// This executes the production child block. CLI registration, logging and PDO
// selection are explicit boundaries; it does not attribute eval lines as coverage.
$root = dirname(__DIR__, 2);
require $root . '/include/vendor/autoload.php';
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
define('MAX_RECACHE_RUNTIME', 1800);
$mode = $argv[1];
$primaryClass = in_array($mode, ['restore-fault', 'ack-fault'], true) ? QueuedConsumerFaultPdo::class : PDO::class;
$primary = new $primaryClass(getenv('KADUPUL_PURGE_PRIMARY_DSN'), getenv('KADUPUL_TEST_MYSQL_USER'), getenv('KADUPUL_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$remote = new PDO(getenv('KADUPUL_PURGE_REMOTE_DSN'), getenv('KADUPUL_TEST_MYSQL_USER'), getenv('KADUPUL_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$events = [];
$observed = [];
$apiEnqueues = 0;
$healthy = in_array($mode, ['healthy', 'healthy-fail', 'healthy-deleted', 'healthy-missing'], true);
if ($healthy) {
    // Only owned fixture schemas: faithful queried columns/identity scopes,
    // not a claim of complete production DDL equivalence.
    $tables = [
        'host_graph' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'host_snmp_query' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'host_snmp_cache' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'poller_item' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'poller_reindex' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'graph_tree_items' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'reports_items' => 'host_id MEDIUMINT UNSIGNED NOT NULL',
        'data_local' => 'id MEDIUMINT UNSIGNED PRIMARY KEY, host_id MEDIUMINT UNSIGNED NOT NULL',
        'graph_local' => 'id MEDIUMINT UNSIGNED PRIMARY KEY, host_id MEDIUMINT UNSIGNED NOT NULL',
        'data_template_data' => 'id MEDIUMINT UNSIGNED PRIMARY KEY, local_data_id MEDIUMINT UNSIGNED NOT NULL',
        'data_template_rrd' => 'id MEDIUMINT UNSIGNED PRIMARY KEY, local_data_id MEDIUMINT UNSIGNED NOT NULL',
        'graph_templates_item' => 'id MEDIUMINT UNSIGNED PRIMARY KEY, local_graph_id MEDIUMINT UNSIGNED NOT NULL',
        'data_input_data' => 'data_template_data_id MEDIUMINT UNSIGNED NOT NULL, data_input_field_id MEDIUMINT UNSIGNED NOT NULL, PRIMARY KEY(data_template_data_id,data_input_field_id)',
    ];
    foreach ($tables as $table => $ddl) {
        $remote->exec('DROP TABLE ' . $table);
        $remote->exec('CREATE TABLE ' . $table . ' (' . $ddl . ') ENGINE=InnoDB');
    }
    $remote->exec("INSERT INTO host VALUES(9,3,'')");
    foreach (array_slice(array_keys($tables), 0, 7) as $table) {
        $remote->exec('INSERT INTO ' . $table . ' VALUES(7),(9)');
    }
    foreach (['data_local' => '70,7),(90,9', 'graph_local' => '71,7),(91,9', 'data_template_data' => '700,70),(900,90', 'data_template_rrd' => '701,70),(901,90', 'graph_templates_item' => '702,71),(902,91', 'data_input_data' => '700,1),(900,1'] as $table => $values) {
        $remote->exec('INSERT INTO ' . $table . ' VALUES(' . $values . ')');
    }
    $remote->exec("INSERT INTO poller_command VALUES(3,3,'7','2026-10-03 01:00:00','2026-10-03 01:00:01'),(3,3,'9','2026-10-03 01:00:00','2026-10-03 01:00:01')");
    if ($mode === 'healthy-fail') {
        $remote->exec("CREATE TRIGGER queued_purge_fail BEFORE DELETE ON host FOR EACH ROW BEGIN IF OLD.id=7 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='owned purge failure'; END IF; END");
    } elseif ($mode === 'healthy-deleted') {
        $primary->exec("UPDATE host SET poller_id=3,deleted='on' WHERE id=7");
    } elseif ($mode === 'healthy-missing') {
        $primary->exec('DELETE FROM host WHERE id=7');
    }
    $api = file_get_contents($root . '/lib/api_device.php');
    if ($api === false) {
        throw new RuntimeException('Missing actual API source');
    }
    eval(test_php_function_source($api, 'api_device_purge_from_remote'));
}
if ($mode === 'empty') {
    $primary->exec('DELETE FROM poller_command');
} elseif (in_array($mode, ['unknown', 'ack-fault'], true)) {
    $primary->exec('UPDATE poller_command SET action=99');
}
function db_fetch_assoc_prepared($sql, $values, $log = true, $connection = null): array
{
    global $primary, $mode, $observed;
    $query = ($connection ?? $primary)->prepare($sql);
    $query->execute($values);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (str_contains($sql, 'SELECT action, command')) {
        $observed['fetched'] = $rows;
        if ($mode === 'return') {
            $primary->beginTransaction();
            $primary->exec('UPDATE host SET poller_id=3 WHERE id=7');
            $primary->exec('DELETE FROM poller_command');
            $primary->commit();
        } elseif (in_array($mode, ['current', 'restore-fault'], true)) {
            $primary->exec('UPDATE host SET poller_id=3 WHERE id=7');
        } elseif ($mode === 'unknown') {
            $primary->exec("INSERT INTO poller_command VALUES (3,88,'7:Added','2026-10-03 01:00:02','2026-10-03 01:00:03')");
        }
    }
    return $rows;
}
function db_fetch_cell_prepared($sql, $values, ...$rest): mixed
{
    global $primary;
    $query = $primary->prepare($sql);
    $query->execute($values);
    return $query->fetchColumn();
}
function cacti_sizeof($rows): int
{
    return count($rows);
}
function register_process_start(...$args): bool
{
    return true;
}
function unregister_process(...$args): void {}
function read_config_option($name): int
{
    return 30;
}
function cacti_log($message, ...$args): void
{
    global $events;
    $events[] = $message;
}
function remote_poller_up($id): bool
{
    global $healthy;
    return $healthy && $id === 3;
}
function poller_connect_to_remote($id): PDO
{
    global $remote;
    return $remote;
}
function poller_push_to_remote_db_connect(...$args): never
{
    throw new RuntimeException('Unexpected reconnect');
}
function db_execute($sql, $log = true, $connection = null): bool
{
    if (!$connection instanceof PDO) {
        throw new RuntimeException('Missing captured PDO');
    } return $connection->exec($sql) !== false;
}
function db_execute_prepared($sql, $values, $log = true, $connection = null): bool
{
    global $primary, $apiEnqueues;
    $apiEnqueues++;
    return ($connection ?? $primary)->prepare($sql)->execute($values);
}
$config = ['poller_id' => 1];
if ($mode === 'offline') {
    $config = ['poller_id' => 3, 'connection' => 'offline'];
}
$database_hostname = 'native';
$database_port = '3306';
$database_default = 'owned';
$database_sessions = ['native:3306:owned' => $primary];
$remote_db_cnn_id = null;
$poller_id = 3;
$host_id = 7;
$poller_db_cnn_id = $primary;
$verbosity = 0;
$start = microtime(true);
$source = file_get_contents($root . '/poller_commands.php');
if ($source === false) {
    throw new RuntimeException('Missing consumer source');
}
$block = test_php_block_source($source, 'else', "cacti_log('NOTE: No Poller Commands found for processing'");
eval('if (false) {} ' . $block);
$dependents = [];
$unselectedDependents = [];
if ($healthy) {
    foreach (['host' => ['id', 7, 9], 'host_graph' => ['host_id', 7, 9], 'host_snmp_query' => ['host_id', 7, 9], 'host_snmp_cache' => ['host_id', 7, 9], 'poller_item' => ['host_id', 7, 9], 'poller_reindex' => ['host_id', 7, 9], 'graph_tree_items' => ['host_id', 7, 9], 'reports_items' => ['host_id', 7, 9], 'data_local' => ['id', 70, 90], 'graph_local' => ['id', 71, 91], 'data_template_data' => ['id', 700, 900], 'data_template_rrd' => ['id', 701, 901], 'graph_templates_item' => ['id', 702, 902], 'data_input_data' => ['data_template_data_id', 700, 900], 'poller_command' => ['command', '7', '9']] as $table => [$column, $selected, $unselected]) {
        $query = $remote->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $column . ' = ?');
        $query->execute([$selected]);
        $dependents[$table] = (int) $query->fetchColumn();
        $query->closeCursor();
        $query->execute([$unselected]);
        $unselectedDependents[$table] = (int) $query->fetchColumn();
    }
}

echo json_encode([
    'mode' => $mode,
    'api_enqueues' => $apiEnqueues,
    'api_sha256' => hash_file('sha256', $root . '/lib/api_device.php'),
    'verifier_sha256' => hash_file('sha256', $root . '/src/Inventory/Infrastructure/Legacy/DeviceCollectorReplication.php'),
    'dependents' => $dependents,
    'unselected_dependents' => $unselectedDependents,
    'unselected_host' => (int) $remote->query('SELECT COUNT(*) FROM host WHERE id=9')->fetchColumn(),
    'unselected_polling' => (int) $remote->query('SELECT COUNT(*) FROM poller_item WHERE host_id=9')->fetchColumn(),
    'fetched' => $observed['fetched'],
    'primary_owner' => $primary->query('SELECT poller_id FROM host WHERE id=7')->fetchColumn(),
    'queue' => $primary->query('SELECT action,command,time,last_updated FROM poller_command ORDER BY action')->fetchAll(PDO::FETCH_ASSOC),
    'remote_host' => $remote->query('SELECT COUNT(*) FROM host WHERE id=7')->fetchColumn(),
    'remote_polling' => $remote->query('SELECT COUNT(*) FROM poller_item WHERE host_id=7')->fetchColumn(),
    'events' => $events,
    'source_sha256' => hash('sha256', $source),
    'scope' => 'Source-backed child dispatch with native PDO persistence; no installed CLI/bootstrap or child coverage import claim.',
], JSON_THROW_ON_ERROR) . "\n";

/** Inject actual server SQL failures after the acknowledged mutation. */
final class QueuedConsumerFaultPdo extends PDO
{
    private bool $committed = false;
    private bool $acknowledging = false;

    public function commit(): bool
    {
        $result = parent::commit();
        $this->committed = $result;
        return $result;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        global $mode;
        if ($mode === 'restore-fault' && $this->committed && str_starts_with($query, 'SET SESSION')) {
            return parent::prepare('SET SESSION queued_purge_nonexistent_option = 1', $options);
        }
        if ($mode === 'ack-fault') {
            if (str_starts_with($query, 'DELETE FROM poller_command WHERE poller_id')) {
                $this->acknowledging = true;
            } elseif ($this->acknowledging && str_starts_with($query, 'SELECT command FROM poller_command')) {
                return parent::prepare('SELECT * FROM queued_purge_nonexistent_table', $options);
            }
        }
        return parent::prepare($query, $options);
    }
}
