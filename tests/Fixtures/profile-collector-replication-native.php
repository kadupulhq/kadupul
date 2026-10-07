<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (!empty(json_decode($argv[1], true)['cli'])) {
    $scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
    $directory = $argv[2];
    mkdir($directory . '/cli');
    mkdir($directory . '/include');
    mkdir($directory . '/lib');
    copy(dirname(__DIR__, 2) . '/cli/poller_replicate.php', $directory . '/cli/poller_replicate.php');
    file_put_contents($directory . '/lib/poller.php', '<?php');
    file_put_contents($directory . '/include/cli_check.php', <<<'PHP'
<?php
$config = ['base_path' => dirname(__DIR__), 'poller_id' => 1];
$calls = [];
$log = [];
$database = new PDO('sqlite::memory:');
$database->exec('CREATE TABLE poller (id INTEGER PRIMARY KEY, disabled TEXT, requires_sync TEXT, last_sync TEXT)');
$database->exec("INSERT INTO poller VALUES (2,'','on',''),(3,'','on','')");
function cacti_sizeof($value) { return count($value); }
function db_fetch_assoc($sql) { return $GLOBALS['database']->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
function db_fetch_assoc_prepared($sql, $params) { $query = $GLOBALS['database']->prepare($sql); $query->execute($params); return $query->fetchAll(PDO::FETCH_ASSOC); }
function register_process_start(...$args) { return true; }
function replicate_out($id, $class) { if ($id === 2 && (getenv('COLLECTOR_CLI_FAILURE') === '1' || getenv('COLLECTOR_CLI_COMPLETION_FAILURE') === '1')) { return false; } if (!in_array($class, ['all', 'data'], true)) { return true; } return db_execute_prepared('UPDATE poller SET last_sync=NOW(), requires_sync="" WHERE id=?', [$id]); }
function db_execute_prepared($sql, $params) { $GLOBALS['calls'][] = [$sql, $params]; $query = $GLOBALS['database']->prepare(str_replace('NOW()', "datetime('now')", $sql)); return $query->execute($params); }
function cacti_log($message, ...$args) { $GLOBALS['log'][] = $message; }
function unregister_process(...$args) { $GLOBALS['unregistered'] = true; }
register_shutdown_function(function () {
    file_put_contents(dirname(__DIR__) . '/cli-state.json', json_encode(['calls' => $GLOBALS['calls'], 'log' => $GLOBALS['log'], 'unregistered' => $GLOBALS['unregistered'] ?? false, 'pollers' => $GLOBALS['database']->query('SELECT * FROM poller ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)], JSON_THROW_ON_ERROR));
});
PHP);
    putenv('COLLECTOR_CLI_COMPLETION_FAILURE=' . (!empty($scenario['completion_failure']) ? '1' : '0'));
    putenv('COLLECTOR_CLI_FAILURE=' . (!empty($scenario['failure']) ? '1' : '0'));
    $command = [PHP_BINARY, '-d', 'opcache.jit=0', '-d', 'opcache.jit_buffer_size=0', '-d', 'error_reporting=24575', '-d', 'pcov.directory=/'];
    if (isset($argv[3])) {
        $bootstrap = '<?php define("RRD_TEST_COVERAGE_DIRECTORY", __DIR__); define("RRD_TEST_CLI_COVERAGE_COPY", ' . var_export($directory . '/cli/poller_replicate.php', true) . '); define("RRD_TEST_CLI_COVERAGE_SOURCE", ' . var_export(dirname(__DIR__, 2) . '/cli/poller_replicate.php', true) . '); require ' . var_export(__DIR__ . '/rrd-process-coverage.php', true) . ';';
        file_put_contents($directory . '/coverage.php', $bootstrap);
        $command[] = '-d';
        $command[] = 'auto_prepend_file=' . $directory . '/coverage.php';
    }
    $command[] = $directory . '/cli/poller_replicate.php';
    $command[] = '--class=' . ($scenario['class'] ?? 'all');
    if (!empty($scenario['selected'])) {
        $command[] = '--poller=2';
    }
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $state = json_decode(file_get_contents($directory . '/cli-state.json'), true, flags: JSON_THROW_ON_ERROR);
    $state += ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    file_put_contents($directory . '/result.json', json_encode($state, JSON_THROW_ON_ERROR));
    unlink($directory . '/cli/poller_replicate.php');
    unlink($directory . '/include/cli_check.php');
    unlink($directory . '/lib/poller.php');
    unlink($directory . '/cli-state.json');
    rmdir($directory . '/cli');
    rmdir($directory . '/include');
    rmdir($directory . '/lib');
    exit;
}
$root = dirname(__DIR__, 2);
$directory = $argv[2];
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
// This profile-only schema exercises the historical 1.2.33 -> 1.2.34
// Installer boundary. Current-release forward repairs require the owned full
// schema in run_schema_repair_contracts.py; never run them on prefixed tables.
define('CACTI_VERSION', !empty($scenario['upgrade_entry']) ? '1.2.34' : trim(file_get_contents($root . '/include/cacti_version')));
require_once $root . '/include/global_constants.php';
require $root . '/lib/poller.php';
require $root . '/lib/api_device.php';
require $root . '/lib/data_source_profile_integrity.php';
function collector_connection(bool $admin = false): PDO
{
    $prefix = $admin && getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
    return new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv($prefix . 'USER') ?: 'root', getenv($prefix . 'PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
}
$source = collector_connection();
if (!empty($scenario['source_active'])) {
    $source->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
}
$database_hostname = 'profile-source';
$database_port = '0';
$database_default = 'catalog';
$database_sessions = ['profile-source:0:catalog' => $source];
$remote = collector_connection();
$installer = collector_connection(true);
$suffix = bin2hex(random_bytes(6));
$maps = [
    'source' => ['data_source_profiles' => 'src_profiles_' . $suffix, 'data_template_data' => 'src_data_' . $suffix],
    'remote' => ['data_source_profiles' => 'rc_profiles_' . $suffix, 'data_template_data' => 'rc_data_' . $suffix],
];
foreach ($maps as $side => &$map) {
    $map['data_source_profiles_rra'] = $side . '_rra_' . $suffix;
    $map['data_source_profiles_cf'] = $side . '_cf_' . $suffix;
}
unset($map);
$maps['source']['poller'] = 'src_poller_' . $suffix;
$maps['source']['version'] = 'src_version_' . $suffix;
$maps['remote']['version'] = 'rc_version_' . $suffix;
$calls = [];
$log = [];
$affected = 0;
$config = ['poller_id' => in_array($scenario['failure'] ?? '', ['connect', 'retry-state'], true) ? 1 : 2, 'is_web' => true];
$local_db_cnn_id = $remote;
$hooks = [];
$messages = [];
function collector_statement(string $sql, array $params = [], $connection = false): PDOStatement
{
    $connection = $connection ?: $GLOBALS['source'];
    $side = $connection === $GLOBALS['source'] ? 'source' : 'remote';
    $GLOBALS['calls'][] = [$side, $sql, count($params), array_sum(array_map(static fn($value) => strlen((string) $value), $params))];
    if (str_contains($sql, 'information_schema.TABLES') || str_contains($sql, 'information_schema.TRIGGERS') || str_contains($sql, 'information_schema.STATISTICS')) {
        $params = array_map(static fn($name) => $GLOBALS['maps'][$side][$name] ?? str_replace('kadupul_profile_reference', ($side === 'source' ? 'collector_source_guard_' : 'collector_guard_') . $GLOBALS['suffix'], $name), $params);
    }
    foreach ($GLOBALS['maps'][$side] as $logical => $physical) {
        $sql = preg_replace('/\b' . $logical . '\b/', $physical, $sql);
    }
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $GLOBALS['affected'] = $statement->rowCount();
    return $statement;
}
function db_begin_transaction()
{
    return !$GLOBALS['source']->inTransaction() && $GLOBALS['source']->beginTransaction();
}
function db_commit_transaction()
{
    return $GLOBALS['source']->commit();
}
function db_rollback_transaction()
{
    return $GLOBALS['source']->rollBack();
}
function db_fetch_assoc_prepared($sql, $params = [], $log = true, $connection = false)
{
    if (!empty($GLOBALS['upgrade_active'])) {
        $connection = $GLOBALS['remote'];
    }
    if (!empty($GLOBALS['scenario']['entrypoint'])) {
        if (str_contains($sql, 'SELECT dtd.*')) {
            $GLOBALS['calls'][] = ['source', $sql];
            return $GLOBALS['data'];
        }
        if (!preg_match('/data_source_profiles|\bversion\b|information_schema.TABLES|information_schema.TRIGGERS|information_schema.STATISTICS|SHOW COLUMNS FROM data_template_data|FROM data_template_data WHERE/', $sql)) {
            $GLOBALS['calls'][] = ['source', $sql];
            return [];
        }
    }
    $rows = collector_statement($sql, $params, $connection)->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($GLOBALS['scenario']['snapshot_edit']) && !$connection && str_contains($sql, 'SELECT * FROM data_source_profiles WHERE')) {
        $editor = collector_connection();
        $editor->exec('SET SESSION innodb_lock_wait_timeout=1');
        $editor->beginTransaction();
        try {
            if (($GLOBALS['scenario']['source_insert'] ?? '') === 'rra') {
                $editor->exec('INSERT INTO `' . $GLOBALS['maps']['source']['data_source_profiles_rra'] . '` VALUES (79,77,9,999)');
            } elseif (($GLOBALS['scenario']['source_insert'] ?? '') === 'cf') {
                $editor->exec('INSERT INTO `' . $GLOBALS['maps']['source']['data_source_profiles_cf'] . '` VALUES (77,4)');
            } else {
                $editor->exec('UPDATE `' . $GLOBALS['maps']['source']['data_source_profiles'] . '` SET step=120 WHERE id=77');
            }
            $GLOBALS['snapshot_blocked'] = false;
        } catch (PDOException $error) {
            $GLOBALS['snapshot_blocked'] = (int) ($error->errorInfo[1] ?? 0) === 1205;
        } finally {
            $editor->rollBack();
        }
    }
    if (str_contains($sql, 'information_schema.TRIGGERS')) {
        $side = !$connection || $connection === $GLOBALS['source'] ? 'source' : 'remote';
        // Only fixture table/trigger identities differ from the production catalog.
        foreach ($rows as &$row) {
            $row['TRIGGER_NAME'] = str_replace(($side === 'source' ? 'collector_source_guard_' : 'collector_guard_') . $GLOBALS['suffix'], 'kadupul_profile_reference', $row['TRIGGER_NAME']);
            foreach ($GLOBALS['maps'][$side] as $logical => $physical) {
                foreach (['EVENT_OBJECT_TABLE', 'ACTION_STATEMENT'] as $field) {
                    if (isset($row[$field])) {
                        $row[$field] = str_replace($physical, $logical, $row[$field]);
                    }
                }
            }
        }
        unset($row);
    }
    return $rows;
}
function db_fetch_assoc($sql, $log = true, $connection = false)
{
    return db_fetch_assoc_prepared($sql, [], $log, $connection);
}
function db_fetch_cell($sql, $default = '', $log = true, $connection = false)
{
    if (!empty($GLOBALS['scenario']['entrypoint']) && !str_contains($sql, 'data_template_data') && !str_contains($sql, 'version')) {
        return 0;
    }
    return collector_statement($sql, [], $connection)->fetchColumn();
}
function db_fetch_row($sql, $log = true, $connection = false)
{
    if (!empty($GLOBALS['scenario']['entrypoint']) && str_starts_with($sql, 'SHOW CREATE TABLE') && !str_contains($sql, 'data_template_data') && !str_contains($sql, 'data_source_profiles') && !str_contains($sql, 'version')) {
        return [];
    }
    $row = collector_statement($sql, [], $connection)->fetch(PDO::FETCH_ASSOC) ?: [];
    if (isset($row['Create Table'])) {
        foreach ($GLOBALS['maps']['source'] as $logical => $physical) {
            $row['Create Table'] = str_replace($physical, $logical, $row['Create Table']);
        }
    }
    return $row;
}
function db_execute($sql, $log = true, $connection = false)
{
    if (!empty($GLOBALS['upgrade_active'])) {
        $connection = $GLOBALS['remote'];
    }
    if (!empty($GLOBALS['scenario']['entrypoint']) && !str_contains($sql, 'data_template_data') && !str_contains($sql, 'data_source_profiles') && !str_contains($sql, 'version')) {
        $GLOBALS['calls'][] = ['source', $sql];
        return true;
    }
    try {
        collector_statement($sql, [], $connection);
        return true;
    } catch (PDOException $error) {
        if (str_contains($sql, 'data_template_data')) {
            return false;
        }
        throw $error;
    }
}
function db_table_exists($table, $log = true, $connection = false)
{
    $connection = $connection ?: $GLOBALS['source'];
    $side = $connection === $GLOBALS['source'] ? 'source' : 'remote';
    $query = $connection->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $query->execute([$GLOBALS['maps'][$side][$table]]);
    return (int) $query->fetchColumn() === 1;
}
function db_column_exists($table, $column, $log = true, $connection = false)
{
    return in_array($column, array_column(db_fetch_assoc('SHOW COLUMNS FROM ' . $table, $log, $connection), 'Field'), true);
}
function sql_save($row, $table, $key = 'id', $autoinc = true, $connection = false)
{
    $columns = array_keys($row);
    $updates = array_map(static fn($column) => '`' . $column . '`=VALUES(`' . $column . '`)', $columns);
    collector_statement('INSERT INTO ' . $table . ' (`' . implode('`,`', $columns) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(',', $updates), array_values($row), $connection);
    return $row['id'];
}
function db_qstr($value)
{
    return $GLOBALS['source']->quote((string) $value);
}
function db_affected_rows($connection)
{
    return $GLOBALS['affected'];
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log($message, ...$options)
{
    $GLOBALS['log'][] = $message;
}
function array_rekey($rows, $key, $value)
{
    return array_column($rows, $value, $key);
}
function db_execute_prepared($sql, $params = [], $log = true, $connection = false)
{
    if (str_contains($sql, 'data_source_profiles') || str_contains($sql, 'data_template_data') || str_starts_with($sql, 'UPDATE poller SET')) {
        try {
            collector_statement($sql, $params, $connection);
            return true;
        } catch (PDOException $error) {
            if (str_contains($sql, 'data_template_data') || str_starts_with($sql, 'UPDATE poller SET')) {
                return false;
            }
            throw $error;
        }
    }
    $GLOBALS['calls'][] = ['source', $sql];
    return true;
}
function db_fetch_cell_prepared($sql, $params = [], ...$options)
{
    $GLOBALS['calls'][] = ['availability', $sql];
    return ($GLOBALS['scenario']['failure'] ?? '') === 'unavailable' ? 0 : 1;
}
function db_fetch_row_prepared($sql, $params = [], ...$options)
{
    $GLOBALS['calls'][] = [str_contains($sql, 'FROM poller') ? 'connect' : 'source', $sql];
    return [];
}
function db_index_exists($table, $index)
{
    $rows = collector_statement('SHOW INDEX FROM ' . $table, [], $GLOBALS['remote'])->fetchAll(PDO::FETCH_ASSOC);
    return in_array($index, array_column($rows, 'Key_name'), true);
}
function db_install_execute($sql)
{
    collector_statement($sql, [], $GLOBALS['installer']);
}
function get_auth_realms()
{
    return [];
}
function get_rrdtool_version()
{
    return '1.8';
}
function __x($context, $message, ...$arguments)
{
    return $message;
}
function cacti_version_compare($a, $b, $operator)
{
    return version_compare($a, $b, $operator);
}
function get_cacti_cli_version()
{
    return $GLOBALS['remote']->query('SELECT cacti FROM `' . $GLOBALS['maps']['remote']['version'] . '`')->fetchColumn();
}
function log_install_always(...$arguments) {}
function log_install_debug(...$arguments) {}
function set_install_config_option($key, $value)
{
    if ($key === 'install_cache_db') {
        $GLOBALS['upgrade_cache_file'] = $value;
    }
}
function read_config_option($name)
{
    return 300;
}
function api_plugin_hook(...$arguments) {}
function api_plugin_hook_function($name, $arguments)
{
    $GLOBALS['hooks'][] = $name;
}
function raise_message($name, ...$arguments)
{
    $GLOBALS['messages'][] = $name;
}
function __($message)
{
    return $message;
}
$upgradeSchema = null;
try {
    if (!empty($scenario['upgrade_entry'])) {
        // Installer DDL uses the existing fixture administrator. Isolate an
        // actual persistent version table for the public PDO writer.
        $ownedName = 'profile_upgrade_' . bin2hex(random_bytes(8));
        if ($installer->exec("CREATE DATABASE `$ownedName`") === false) {
            throw new RuntimeException('Could not create the owned installer schema.');
        }
        $upgradeSchema = $ownedName;
        $source = collector_connection(true);
        $remote = collector_connection(true);
        foreach ([$source, $remote, $installer] as $connection) {
            $connection->exec("USE `$upgradeSchema`");
        }
        $database_sessions['profile-source:0:catalog'] = $source;
        $local_db_cnn_id = $remote;
        $maps['remote']['version'] = 'version';
    }
    $source->exec('CREATE TABLE `' . $maps['source']['poller'] . '` (id INTEGER PRIMARY KEY, requires_sync VARCHAR(2), last_sync VARCHAR(30) DEFAULT "") ENGINE=InnoDB');
    $source->exec('INSERT INTO `' . $maps['source']['poller'] . '` VALUES (2,"",""),(3,"on","")');
    foreach ($maps as $side => $map) {
        $connection = $side === 'source' ? $source : $remote;
        $connection->exec('CREATE TABLE `' . $map['data_source_profiles'] . '` (id INTEGER PRIMARY KEY, name VARCHAR(32), step INTEGER) ENGINE=InnoDB');
        $connection->exec('CREATE TABLE `' . $map['data_template_data'] . '` (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER NOT NULL, name VARCHAR(32), KEY data_source_profile_id (data_source_profile_id)) ENGINE=InnoDB');
        $connection->exec('CREATE TABLE `' . $map['data_source_profiles_rra'] . '` (id INTEGER PRIMARY KEY, data_source_profile_id INTEGER, steps INTEGER, `rows` INTEGER) ENGINE=InnoDB');
        $connection->exec('CREATE TABLE `' . $map['data_source_profiles_cf'] . '` (data_source_profile_id INTEGER, consolidation_function_id INTEGER, PRIMARY KEY(data_source_profile_id,consolidation_function_id)) ENGINE=InnoDB');
        $connection->exec('INSERT INTO `' . $map['data_source_profiles_rra'] . '` VALUES (1,1,1,600)');
        $connection->exec('INSERT INTO `' . $map['data_source_profiles_cf'] . '` VALUES (1,1)');
        $connection->exec('INSERT INTO `' . $map['data_source_profiles'] . "` VALUES (1,'default',300)");
    }
    if (in_array($scenario['failure'] ?? '', ['index-missing', 'index-wrong-column', 'index-composite', 'index-equivalent', 'index-unique'], true)) {
        $remote->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` DROP INDEX data_source_profile_id');
        if ($scenario['failure'] !== 'index-missing') {
            $columns = match ($scenario['failure']) {
                'index-wrong-column' => 'id',
                'index-composite' => 'id, data_source_profile_id',
                'index-unique' => 'data_source_profile_id',
                default => 'data_source_profile_id, id',
            };
            $remote->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` ADD ' . ($scenario['failure'] === 'index-unique' ? 'UNIQUE ' : '') . 'INDEX data_source_profile_id (' . $columns . ')');
        }
    }
    if (($scenario['failure'] ?? '') === 'index-prefix') {
        $remote->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` DROP INDEX data_source_profile_id, MODIFY data_source_profile_id VARCHAR(16), ADD INDEX data_source_profile_id (data_source_profile_id(1))');
    }
    if (($scenario['failure'] ?? '') === 'index-hidden') {
        $visibility = str_contains($remote->getAttribute(PDO::ATTR_SERVER_VERSION), 'MariaDB') ? 'IGNORED' : 'INVISIBLE';
        $remote->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` ALTER INDEX data_source_profile_id ' . $visibility);
    }
    foreach (array('source' => $source, 'remote' => $remote) as $side => $connection) {
        $connection->exec('CREATE TABLE `' . $maps[$side]['version'] . '` (cacti VARCHAR(16) PRIMARY KEY) ENGINE=InnoDB');
        $connection->exec('INSERT INTO `' . $maps[$side]['version'] . "` VALUES ('" . ($side === 'source' ? '1.2.34' : '1.2.33') . "')");
    }
    $source->exec('INSERT INTO `' . $maps['source']['data_source_profiles'] . "` VALUES (77,'custom',60)");
    $remote->exec('INSERT INTO `' . $maps['remote']['data_template_data'] . "` VALUES (1,1,'existing')");
    // Stale collector definitions must be replaced, not accumulated.
    $remote->exec('INSERT INTO `' . $maps['remote']['data_source_profiles_rra'] . '` VALUES (79,77,24,900)');
    $remote->exec('INSERT INTO `' . $maps['remote']['data_source_profiles_cf'] . '` VALUES (77,4)');
    foreach (data_source_profile_reference_triggers($maps['remote']['data_source_profiles'], $maps['remote']['data_template_data'], 'collector_guard_' . $suffix, $maps['remote']['data_source_profiles_rra'], $maps['remote']['data_source_profiles_cf']) as $definition) {
        $installer->exec($definition['sql']);
    }
    foreach (data_source_profile_reference_triggers($maps['source']['data_source_profiles'], $maps['source']['data_template_data'], 'collector_source_guard_' . $suffix, $maps['source']['data_source_profiles_rra'], $maps['source']['data_source_profiles_cf']) as $definition) {
        $installer->exec($definition['sql']);
    }
    if (in_array($scenario['failure'] ?? '', ['source-guard-missing', 'source-guard-modified'], true)) {
        $name = 'collector_source_guard_' . $suffix . '_cf_insert';
        $installer->exec("DROP TRIGGER `$name`");
        if ($scenario['failure'] === 'source-guard-modified') {
            $installer->exec("CREATE TRIGGER `$name` AFTER INSERT ON `" . $maps['source']['data_source_profiles_cf'] . "` FOR EACH ROW SET @profile_source_guard_modified=1");
        }
    }
    if (in_array($scenario['failure'] ?? '', ['guard-missing', 'guard-modified'], true)) {
        $name = 'collector_guard_' . $suffix . '_insert';
        $installer->exec("DROP TRIGGER `$name`");
        if ($scenario['failure'] === 'guard-modified') {
            $installer->exec("CREATE TRIGGER `$name` AFTER INSERT ON `" . $maps['remote']['data_template_data'] . "` FOR EACH ROW SET @profile_guard_modified=1");
        }
    }
    if (($scenario['failure'] ?? '') === 'retry-state') {
        $installer->exec('CREATE TRIGGER `collector_retry_reject_' . $suffix . '` BEFORE UPDATE ON `' . $maps['source']['poller'] . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Retry state rejected'");
    }
    if (($scenario['failure'] ?? '') === 'completion-state') {
        $installer->exec('CREATE TRIGGER `collector_completion_reject_' . $suffix . '` BEFORE UPDATE ON `' . $maps['source']['poller'] . "` FOR EACH ROW BEGIN IF NEW.requires_sync='' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Completion state rejected'; END IF; END");
    }
    if (($scenario['failure'] ?? '') === 'copy') {
        $installer->exec('CREATE TRIGGER `collector_reject_' . $suffix . '` BEFORE INSERT ON `' . $maps['remote']['data_source_profiles'] . "` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Parent copy rejected'");
    }
    $source->exec('INSERT INTO `' . $maps['source']['data_source_profiles_rra'] . '` VALUES (77,77,1,600),(78,77,6,700)');
    $source->exec('INSERT INTO `' . $maps['source']['data_source_profiles_cf'] . '` VALUES (77,1),(77,3)');
    if (in_array($scenario['failure'] ?? '', ['rra', 'cf', 'corrupt'], true)) {
        $table = ($scenario['failure'] ?? '') === 'rra' ? 'data_source_profiles_rra' : 'data_source_profiles_cf';
        $body = ($scenario['failure'] ?? '') === 'corrupt' ? 'SET NEW.consolidation_function_id=4' : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Definition copy rejected'";
        $installer->exec('CREATE TRIGGER `collector_definition_reject_' . $suffix . '` BEFORE INSERT ON `' . $maps['remote'][$table] . '` FOR EACH ROW ' . $body);
    }
    if (($scenario['failure'] ?? '') === 'missing-rra') {
        $source->exec('DELETE FROM `' . $maps['source']['data_source_profiles_rra'] . '` WHERE data_source_profile_id=77');
    }
    if (($scenario['failure'] ?? '') === 'missing-cf') {
        $source->exec('DELETE FROM `' . $maps['source']['data_source_profiles_cf'] . '` WHERE data_source_profile_id=77');
    }
    if (($scenario['failure'] ?? '') === 'collision') {
        $remote->exec('INSERT INTO `' . $maps['remote']['data_source_profiles_rra'] . '` VALUES (77,1,24,900)');
    }
    if (($scenario['failure'] ?? '') === 'engine') {
        $remote->exec('ALTER TABLE `' . $maps['remote']['data_source_profiles_cf'] . '` ENGINE=MyISAM');
    }
    if (in_array($scenario['failure'] ?? '', ['child-write','child-late','child-delete'], true)) {
        $event = $scenario['failure'] === 'child-delete' ? 'DELETE' : 'INSERT';
        $body = $scenario['failure'] === 'child-late' ? "BEGIN IF NEW.id=502 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reference rejected'; END IF; END" : "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Reference rejected'";
        $installer->exec('CREATE TRIGGER `collector_child_reject_' . $suffix . '` BEFORE ' . $event . ' ON `' . $maps['remote']['data_template_data'] . '` FOR EACH ROW ' . $body);
    }
    if (($scenario['failure'] ?? '') === 'child-corrupt') {
        $installer->exec('CREATE TRIGGER `collector_child_alter_' . $suffix . '` BEFORE INSERT ON `' . $maps['remote']['data_template_data'] . '` FOR EACH ROW SET NEW.name=\'changed\'');
    }
    if (($scenario['failure'] ?? '') === 'source-engine') {
        $source->exec('ALTER TABLE `' . $maps['source']['data_source_profiles_rra'] . '` ENGINE=MyISAM');
    }
    if (($scenario['failure'] ?? '') === 'child-engine') {
        $remote->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` ENGINE=MyISAM');
    }
    if (($scenario['failure'] ?? '') === 'child-schema') {
        $remote->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` DROP COLUMN name');
    }
    if (in_array($scenario['failure'] ?? '', ['batch-late', 'batch-corrupt'], true)) {
        $mutation = $scenario['failure'] === 'batch-late' ? "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Late batch rejected'" : "SET NEW.name='changed'";
        $installer->exec('CREATE TRIGGER `collector_batch_' . $suffix . '` BEFORE INSERT ON `' . $maps['remote']['data_template_data'] . '` FOR EACH ROW BEGIN IF NEW.id=1002 THEN ' . $mutation . '; END IF; END');
    }
    $id = ($scenario['failure'] ?? '') === 'missing' ? 98 : 77;
    $data = [['id' => 2, 'data_source_profile_id' => $id, 'name' => 'replicated']];
    if (($scenario['failure'] ?? '') === 'child-late') {
        $data = [];
        foreach (range(2, 502) as $childId) {
            $data[] = ['id' => $childId, 'data_source_profile_id' => 77, 'name' => 'replicated'];
        }
    }
    if (isset($scenario['batch_rows'])) {
        $data = [];
        $name = str_repeat('x', $scenario['name_bytes'] ?? 8);
        if (strlen($name) > 32) {
            foreach ($maps as $map) {
                $remote->exec('ALTER TABLE `' . $map['data_template_data'] . '` MODIFY name LONGTEXT');
            }
        }
        for ($column = 0; $column < ($scenario['extra_columns'] ?? 0); $column++) {
            foreach ($maps as $map) {
                $remote->exec('ALTER TABLE `' . $map['data_template_data'] . '` ADD extra_' . $column . ' INTEGER DEFAULT 0');
            }
        }
        for ($offset = 0; $offset < $scenario['batch_rows']; $offset++) {
            $row = ['id' => $offset + 2, 'data_source_profile_id' => 77, 'name' => $name];
            for ($column = 0; $column < ($scenario['extra_columns'] ?? 0); $column++) {
                $row['extra_' . $column] = $column;
            }
            $data[] = $row;
        }
        if (($scenario['failure'] ?? '') === 'batch-duplicate') {
            $data[] = $data[0];
        }
        if (($scenario['failure'] ?? '') === 'batch-excluded') {
            $data[0]['id'] = 1;
        }
    }
    if (!empty($scenario['source_active'])) {
        $source->beginTransaction();
        $source->exec('UPDATE `' . $maps['source']['data_source_profiles'] . '` SET step=301 WHERE id=1');
    }
    $upgrade_error = null;
    $retry_initial = null;
    $migration_version = null;
    $version_confirmation = null;
    if (!empty($scenario['upgrade_entry'])) {
        $upgrade_active = true;
        $config = ['base_path' => $root, 'poller_id' => 2, 'connection' => 'recovery', 'is_web' => false, 'url_path' => '/', 'cacti_server_os' => 'unix'];
        require_once $root . '/include/global_constants.php';
        require $root . '/include/global_arrays.php';
        if (!isset($cacti_version_codes[CACTI_VERSION])) {
            throw new RuntimeException('Profile migration target is not registered.');
        }
        $cacti_version_codes = array_filter($cacti_version_codes, static fn($version) => version_compare($version, CACTI_VERSION, '<='), ARRAY_FILTER_USE_KEY);
        require $root . '/lib/installer.php';
        $class = new ReflectionClass(Installer::class);
        $upgrade = $class->newInstanceWithoutConstructor();
        $class->getProperty('old_cacti_version')->setValue($upgrade, '1.2.33');
        ob_start();
        try {
            $result = $class->getMethod('upgradeDatabase')->invoke($upgrade);
        } catch (RuntimeException $error) {
            $upgrade_error = $error->getMessage();
            $result = false;
        } finally {
            ob_end_clean();
            if (isset($upgrade_cache_file)) {
                unlink($upgrade_cache_file);
            }
            $upgrade_active = false;
        }
        $migration_version = get_cacti_cli_version();
        if (!empty($scenario['upgrade_retry'])) {
            $retry_initial = ['error' => $upgrade_error, 'result' => $result, 'version' => $migration_version];
            if ($upgrade_error === null || ($scenario['failure'] ?? '') !== 'index-wrong-column') {
                throw new RuntimeException('Expected incompatible profile index refusal before retry.');
            }
            $installer->exec('ALTER TABLE `' . $maps['remote']['data_template_data'] . '` DROP INDEX data_source_profile_id');
            $upgrade_error = null;
            $upgrade_active = true;
            ob_start();
            try {
                $result = $class->getMethod('upgradeDatabase')->invoke($upgrade);
            } catch (RuntimeException $error) {
                $upgrade_error = $error->getMessage();
                $result = false;
            } finally {
                ob_end_clean();
                if (isset($upgrade_cache_file)) {
                    unlink($upgrade_cache_file);
                }
                $upgrade_active = false;
            }
            $migration_version = get_cacti_cli_version();
        }
        if ($upgrade_error === null && $result === false) {
            $version_confirmation = Installer::recordInstalledVersion();
        }
    } elseif (isset($scenario['batch_rows'])) {
        $result = replicate_data_source_profile_children($remote, $data, $scenario['collector'] === 'bulk', $scenario['exclude'] ?? false);
    } elseif (!empty($scenario['entrypoint'])) {
        $result = $scenario['collector'] === 'bulk' ? replicate_out(2, $scenario['class'] ?? 'all') : api_device_replicate_out(1, 2);
    } elseif (($scenario['collector'] ?? '') === 'bulk') {
        replicate_out_table($remote, $data, 'data_template_data', 2);
    } else {
        replicate_table_to_poller($remote, $data, 'data_template_data');
    }
    $rows = $remote->query('SELECT * FROM `' . $maps['remote']['data_template_data'] . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $parent = $remote->query('SELECT id FROM `' . $maps['remote']['data_source_profiles'] . '` WHERE id=77')->fetchColumn();
    $rras = $remote->query('SELECT r.steps,r.`rows`,c.consolidation_function_id FROM `' . $maps['remote']['data_template_data'] . '` d JOIN `' . $maps['remote']['data_source_profiles_rra'] . '` r ON r.data_source_profile_id=d.data_source_profile_id JOIN `' . $maps['remote']['data_source_profiles_cf'] . '` c ON c.data_source_profile_id=d.data_source_profile_id WHERE d.id=2')->fetchAll(PDO::FETCH_ASSOC);
    $sourceActive = $source->inTransaction();
    $callerBefore = $source->query('SELECT step FROM `' . $maps['source']['data_source_profiles'] . '` WHERE id=1')->fetchColumn();
    if (!empty($scenario['source_active']) && $sourceActive) {
        $source->rollBack();
    }
    $callerAfter = $source->query('SELECT step FROM `' . $maps['source']['data_source_profiles'] . '` WHERE id=1')->fetchColumn();
    file_put_contents($directory . '/result.json', json_encode(['retry_initial' => $retry_initial, 'upgrade_target' => !empty($scenario['upgrade_entry']) ? CACTI_VERSION : null, 'migration_version' => $migration_version, 'version_confirmation' => $version_confirmation, 'upgrade_error' => $upgrade_error, 'remote_version' => $remote->query('SELECT cacti FROM `' . $maps['remote']['version'] . '`')->fetchColumn(), 'caller_before' => $callerBefore, 'caller_after' => $callerAfter, 'source_active' => $sourceActive, 'snapshot_blocked' => $snapshot_blocked ?? false, 'remote_step' => $remote->query('SELECT step FROM `' . $maps['remote']['data_source_profiles'] . '` WHERE id=77')->fetchColumn(), 'sync' => $source->query('SELECT requires_sync FROM `' . $maps['source']['poller'] . '` ORDER BY id')->fetchAll(PDO::FETCH_COLUMN), 'rras' => $rras, 'result' => $result ?? null, 'hooks' => $hooks, 'messages' => $messages, 'rows' => $rows, 'parent' => $parent, 'log' => $log, 'calls' => $calls], JSON_THROW_ON_ERROR));
} finally {
    if ($upgradeSchema !== null) {
        $installer->exec("DROP DATABASE `$upgradeSchema`");
    } else {
        $source->exec('DROP TABLE IF EXISTS `' . $maps['source']['poller'] . '`');
        foreach ($maps as $map) {
            $remote->exec('DROP TABLE IF EXISTS `' . $map['version'] . '`');
            $remote->exec('DROP TABLE IF EXISTS `' . $map['data_template_data'] . '`');
            $remote->exec('DROP TABLE IF EXISTS `' . $map['data_source_profiles_rra'] . '`');
            $remote->exec('DROP TABLE IF EXISTS `' . $map['data_source_profiles_cf'] . '`');
            $remote->exec('DROP TABLE IF EXISTS `' . $map['data_source_profiles'] . '`');
        }
    }
}
