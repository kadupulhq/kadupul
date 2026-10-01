<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$root = getenv('DISCOVERY_TEST_ROOT');
$directory = getenv('DISCOVERY_TEST_DIRECTORY');
$case = getenv('DISCOVERY_TEST_CASE');
$config = array('base_path' => $root, 'include_path' => $root . '/include', 'poller_id' => 1, 'is_web' => false, 'php_snmp_support' => false, 'cacti_server_os' => 'unix', 'config_options_array' => array('settings_smtp_timeout' => '5', 'settings_how' => '1', 'settings_sendmail_path' => escapeshellarg($directory . '/sendmail'), 'settings_wordwrap' => '76', 'settings_from_name' => '', 'selective_debug' => '', 'client_timezone_support' => '', 'path_cactilog' => $directory . '/audit.log', 'log_destination' => '1', 'log_verbosity' => '5', 'automation_fromname' => '', 'automation_fromemail' => '', 'settings_from_email' => '', 'automation_email' => '', 'admin_user' => ''));
$mail_methods = array(1 => 'inert capture');
$cacti_locale = 'en-US';
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
require $root . '/lib/api_automation.php';
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
$db = new PDO('sqlite::memory:');
$db->sqliteCreateFunction('NOW', static fn() => '2026-01-01 00:00:00');
$db->exec('CREATE TABLE automation_processes (pid INTEGER PRIMARY KEY,poller_id INTEGER,network_id INTEGER,task TEXT,status TEXT,heartbeat TEXT,command TEXT,up_hosts INTEGER DEFAULT 0,snmp_hosts INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE automation_ips (ip_address TEXT,network_id INTEGER,pid INTEGER,thread INTEGER,status INTEGER)');
$db->exec('CREATE TABLE host_snmp_query (host_id INTEGER,snmp_query_id INTEGER)');
$db->exec('CREATE TABLE version (cacti TEXT)');
$db->exec("INSERT INTO version VALUES ('test')");
$db->exec('CREATE TABLE automation_networks (id INTEGER PRIMARY KEY,name TEXT,subnet_range TEXT,last_started TEXT,last_runtime INTEGER,notification_enabled TEXT,notification_email TEXT,notification_fromname TEXT,notification_fromemail TEXT)');
$db->exec('CREATE TABLE automation_devices (id INTEGER PRIMARY KEY,network_id INTEGER,hostname TEXT,ip TEXT,sysName TEXT,snmp INTEGER,up INTEGER,time TEXT)');
$network = array(7, '<b>Network</b>', '<SUBJECT>/24', null, 12, 'on', 'recipient@example.invalid', 'Sender', 'sender@example.invalid');
if ($case === 'disabled') {
    $network[5] = '';
}
if ($case === 'fallback') {
    $network[7] = '';
    $network[8] = '';
}
if ($case === 'no-recipient') {
    $network[6] = '';
}
if ($case !== 'missing') {
    $db->prepare('INSERT INTO automation_networks VALUES (?,?,?,?,?,?,?,?,?)')->execute($network);
}
$names = array('markup' => '<script>alert(1)</script>', 'placeholder' => '<SUBJECT>', 'empty' => '', 'null' => null, 'entity' => '&lt;already&gt;');
$name = array_key_exists($case, $names) ? $names[$case] : 'normal';
$db->prepare('INSERT INTO automation_devices VALUES (?,?,?,?,?,?,?,?)')->execute(array(1, 7, 'host"`<b>x</b>', '<TO>', $name, 1, 0, ''));
function discovery_query($sql, $params = array())
{
    if (str_contains($sql, 'UPDATE automation_ips') && str_contains($sql, 'LIMIT 1')) {
        $sql = 'UPDATE automation_ips SET pid=?,thread=? WHERE rowid IN (SELECT rowid FROM automation_ips WHERE network_id=? AND status=0 AND pid=0 LIMIT 1)';
    }

    $statement = $GLOBALS['db']->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function db_fetch_cell($sql)
{
    return discovery_query($sql)->fetchColumn();
}
function db_fetch_row_prepared($sql, $params, ...$arguments)
{
    return discovery_query($sql, $params)->fetch(PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params, ...$arguments)
{
    $rows = discovery_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    if ($GLOBALS['case'] === 'missing-after-read' && str_contains($sql, 'automation_devices')) {
        $GLOBALS['db']->exec('DELETE FROM automation_networks WHERE id=7');
    }
    return $rows;
}

if (str_starts_with($case, 'worker-') || str_starts_with($case, 'master-')) {
    foreach (array('enabled TEXT', 'poller_id INTEGER', 'dns_servers TEXT', 'enable_netbios TEXT', 'rerun_data_queries TEXT', 'snmp_id INTEGER', 'sched_type TEXT') as $column) {
        $db->exec('ALTER TABLE automation_networks ADD COLUMN ' . $column);
    }
    $db->exec("UPDATE automation_networks SET enabled='on',poller_id=1,dns_servers='',enable_netbios='',rerun_data_queries=''");
    $db->exec("UPDATE automation_networks SET snmp_id=0,sched_type='1'");
    if ($case === 'master-empty') {
        $db->exec('UPDATE automation_networks SET poller_id=2');
    }
    if ($case === 'master-schedule') {
        $db->exec('UPDATE automation_networks SET snmp_id=1');
    }
}
if (str_starts_with($case, 'worker-')) {
    $db->exec('CREATE TABLE automation_templates (host_template INTEGER)');
    $db->exec('CREATE TABLE host_template (id INTEGER,name TEXT)');
    $db->exec('CREATE TABLE host (id INTEGER,hostname TEXT,snmp_version INTEGER,status INTEGER,deleted TEXT)');
    $deleted = $case === 'worker-deleted' ? 'on' : '';
    $db->exec("INSERT INTO host VALUES (9,'127.0.0.1',1,3,'$deleted')");
    $db->exec("INSERT INTO automation_processes (pid,poller_id,network_id,task,status,command) VALUES (71,1,7,'tmaster','running','start')");
    $db->exec("INSERT INTO automation_ips VALUES ('invalid-address',7,0,0,0),('127.0.0.1',7,0,0,0),('unrelated-address',8,0,0,0)");
    $db->exec('ALTER TABLE automation_ips ADD COLUMN hostname TEXT');
}
if (str_starts_with($case, 'worker-snmp-')) {
    $config['config_options_array'] += array('path_snmpget' => $directory . '/snmpget', 'snmp_retries' => '1', 'max_get_size' => '1', 'oid_increasing_check_disable' => '');
    $db->sqliteCreateFunction('CONCAT', static fn(...$values) => implode('', $values));
    $db->sqliteCreateFunction('REGEXP', static fn($pattern, $value) => preg_match($pattern, $value) === 1);
    foreach (array('ping_method INTEGER', 'ping_port INTEGER', 'ping_timeout INTEGER', 'ping_retries INTEGER', 'site_id INTEGER', 'same_sysname TEXT', 'add_to_cacti TEXT') as $column) {
        $db->exec('ALTER TABLE automation_networks ADD COLUMN ' . $column);
    }
    $db->exec("UPDATE automation_networks SET snmp_id=1,ping_method=" . PING_SNMP . ",ping_port=0,ping_timeout=1,ping_retries=1,site_id=1,same_sysname='',add_to_cacti=''");
    $db->exec('DELETE FROM host');
    $db->exec('ALTER TABLE host ADD COLUMN snmp_sysName TEXT');
    foreach (array('sequence INTEGER', 'sysDescr TEXT', 'sysOid TEXT', 'sysName TEXT') as $column) {
        $db->exec('ALTER TABLE automation_templates ADD COLUMN ' . $column);
    }
    $columns = array('snmp_community', 'snmp_version', 'snmp_port', 'snmp_username', 'snmp_password', 'snmp_auth_protocol', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_context', 'sysLocation', 'sysContact', 'sysDescr', 'sysUptime', 'os');
    foreach ($columns as $column) {
        $db->exec('ALTER TABLE automation_devices ADD COLUMN ' . $column . ' TEXT');
    }
    $db->exec('CREATE UNIQUE INDEX discovered_identity ON automation_devices(network_id,ip)');
    $item = array('snmp_id' => 1, 'sequence' => 1, 'snmp_version' => 2, 'snmp_port' => 161, 'snmp_timeout' => 1, 'snmp_retries' => 1, 'snmp_community' => 'public', 'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '', 'snmp_priv_passphrase' => '', 'snmp_priv_protocol' => '', 'snmp_context' => '', 'snmp_engine_id' => '', 'max_oids' => 1, 'bulk_walk_size' => 1);
    $definitions = array_map(static fn($key) => $key . ' TEXT', array_keys($item));
    $db->exec('CREATE TABLE automation_snmp_items (' . implode(',', $definitions) . ')');
    $db->prepare('INSERT INTO automation_snmp_items VALUES (' . implode(',', array_fill(0, count($item), '?')) . ')')->execute(array_values($item));
    if ($case === 'worker-snmp-match') {
        $db->exec("INSERT INTO host_template VALUES (9,'Linux template')");
        $db->exec("INSERT INTO automation_templates VALUES (9,1,'Linux','8072','FixtureNode')");
    }
    if ($case === 'worker-snmp-duplicate') {
        $db->exec("UPDATE automation_devices SET sysName='FixtureNode'");
    }
}
if ($case === 'worker-parent') {
    foreach (array('threads INTEGER', 'up_hosts INTEGER', 'snmp_hosts INTEGER') as $column) {
        $db->exec('ALTER TABLE automation_networks ADD COLUMN ' . $column);
    }
    $db->exec("UPDATE automation_networks SET threads=0,notification_enabled='',subnet_range='127.0.0.1/32'");
    $db->exec('UPDATE automation_processes SET pid=2147483647 WHERE network_id=7');
    $db->exec("INSERT INTO automation_processes (pid,poller_id,network_id,task,status,command) VALUES (888,1,8,'collector','done','start')");
    mkdir($directory . '/inert', 0700);
    file_put_contents($directory . '/inert/poller_automation.php', '<?php file_put_contents(dirname(__DIR__) . "/collector-args.json", json_encode($argv));');
    $config['config_options_array']['path_php_binary'] = PHP_BINARY;
    $config['config_options_array']['path_webroot'] = $directory . '/inert';
}
if ($case === 'worker-cancel') {
    $db->exec("UPDATE automation_processes SET command='cancel' WHERE network_id=7");
}
if ($case === 'admin-missing') {
    $db->exec("UPDATE automation_networks SET notification_email=''");
    $db->exec('CREATE TABLE user_auth (id INTEGER,email_address TEXT,full_name TEXT)');
    $config['config_options_array']['admin_user'] = '9';
}
register_shutdown_function(static function () use ($case, $directory) {
    ob_clean();
    if (str_starts_with($case, 'master-')) {
        $db = $GLOBALS['db'];
        file_put_contents($directory . '/state.json', json_encode(array('tasks' => (int) $db->query('SELECT COUNT(*) FROM automation_processes')->fetchColumn(), 'log' => is_file($directory . '/audit.log') ? file_get_contents($directory . '/audit.log') : '', 'error' => error_get_last()), JSON_THROW_ON_ERROR));
        ob_end_clean();
        return;
    }
    if ($case === 'worker-parent' || $case === 'worker-cancel') {
        $db = $GLOBALS['db'];
        file_put_contents($directory . '/state.json', json_encode(array('error' => error_get_last(), 'network' => $db->query('SELECT * FROM automation_networks WHERE id=7')->fetch(PDO::FETCH_ASSOC), 'processes' => $db->query('SELECT pid,network_id,task FROM automation_processes ORDER BY network_id')->fetchAll(PDO::FETCH_ASSOC), 'ips' => $db->query('SELECT network_id FROM automation_ips ORDER BY network_id')->fetchAll(PDO::FETCH_COLUMN), 'args' => is_file($directory . '/collector-args.json') ? json_decode(file_get_contents($directory . '/collector-args.json'), true) : null), JSON_THROW_ON_ERROR));
        ob_end_clean();
        return;
    }
    if (str_starts_with($case, 'worker-')) {
        $db = $GLOBALS['db'];
        file_put_contents($directory . '/state.json', json_encode(array('found' => $db->query('SELECT hostname,ip,sysName,snmp,up FROM automation_devices ORDER BY ip')->fetchAll(PDO::FETCH_ASSOC), 'error' => error_get_last(), 'task' => $db->query("SELECT status,up_hosts,snmp_hosts FROM automation_processes WHERE task='collector'")->fetch(PDO::FETCH_ASSOC), 'ips' => $db->query('SELECT ip_address,network_id,status FROM automation_ips ORDER BY network_id,ip_address')->fetchAll(PDO::FETCH_ASSOC)), JSON_THROW_ON_ERROR));
        ob_end_clean();
        return;
    }

    if ($case === 'host-fields' || $case === 'host-fields-empty') {
        $db = $GLOBALS['db'];
        $fields = ['snmp_sysDescr', 'snmp_sysObjectID', 'snmp_sysUptimeInstance', 'snmp_sysContact', 'snmp_sysName', 'snmp_sysLocation'];
        $db->exec('CREATE TABLE host (id INTEGER PRIMARY KEY,' . implode(',', array_map(static fn($field) => $field . " TEXT DEFAULT 'existing'", $fields)) . ')');
        $db->exec('INSERT INTO host (id) VALUES (42),(43)');
        $device = $case === 'host-fields' ? ['snmp_sysDescr' => 'Linux <b>node</b>', 'snmp_sysObjectID' => '1.3.6.1', 'snmp_sysUptime' => 123, 'snmp_sysContact' => 'contact', 'snmp_sysName' => 'node', 'snmp_sysLocation' => 'site'] : ['snmp_sysDescr' => null, 'snmp_sysName' => '', 'snmp_sysUptime' => 0];
        updateDiscoveredHostFields(42, $device);
        file_put_contents($directory . '/state.json', json_encode(['hosts' => $db->query('SELECT * FROM host ORDER BY id')->fetchAll(PDO::FETCH_ASSOC), 'error' => error_get_last()], JSON_THROW_ON_ERROR));
        ob_end_clean();
        return;
    }

    if ($case === 'queue') {
        $db = $GLOBALS['db'];
        $db->exec("INSERT INTO automation_ips VALUES ('address',7,71,1,0),('address',8,71,1,0)");
        registerTask(7, 71, 1);
        registerTask(8, 72, 1);
        addUpDevice(7, 71);
        addSNMPDevice(7, 71);
        endTask(7, 71);
        markIPRunning('address', 7);
        $running = $db->query('SELECT status FROM automation_ips WHERE network_id=7')->fetchColumn();
        markIPDone('address', 7);
        $task = $db->query('SELECT status,up_hosts,snmp_hosts,heartbeat FROM automation_processes WHERE pid=71')->fetch(PDO::FETCH_ASSOC);
        $ip = $db->query('SELECT network_id,status FROM automation_ips ORDER BY network_id')->fetchAll(PDO::FETCH_ASSOC);
        $db->exec('UPDATE automation_devices SET up=1');
        updateDownDevice(7, '<TO>');
        $down = $db->query('SELECT up FROM automation_devices')->fetchColumn();
        $network = array('rerun_data_queries' => '');
        rerunDataQueries(7, $network);
        $network['rerun_data_queries'] = 'on';
        rerunDataQueries(7, $network);
        removeMyProcess(71, 7);
        registerTask(7, 73, 1);
        clearTask(7, 73);
        registerTask(7, 74, 1);
        clearAllTasks(7);
        file_put_contents($directory . '/state.json', json_encode(array('running' => $running, 'task' => $task, 'ip' => $ip, 'down' => $down, 'remaining' => $db->query('SELECT pid FROM automation_processes')->fetchAll(PDO::FETCH_COLUMN), 'remainingIps' => $db->query('SELECT network_id FROM automation_ips')->fetchAll(PDO::FETCH_COLUMN)), JSON_THROW_ON_ERROR));
        ob_end_clean();
        return;
    }

    $old = $case === 'existing' ? getNetworkDevices(7) : array();
    reportNetworkStatus(7, $old);
    file_put_contents($directory . '/state.json', json_encode(array('log' => is_file($directory . '/audit.log') ? file_get_contents($directory . '/audit.log') : '', 'sent' => is_file($directory . '/message')), JSON_THROW_ON_ERROR));
    ob_end_clean();
});
if (getenv('DISCOVERY_TEST_COVERAGE') === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/poller_automation.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/poller_automation.php');
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
// Ignore only bootstrap diagnostics recorded before the production CLI starts.
error_clear_last();
ob_start();

function db_fetch_cell_prepared($sql, $params, ...$arguments)
{
    return discovery_query($sql, $params)->fetchColumn();
}
function db_execute_prepared($sql, $params, ...$arguments)
{
    return discovery_query($sql, $params) !== false;
}

function db_fetch_assoc($sql, ...$arguments)
{
    return discovery_query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function db_table_exists($table, ...$arguments)
{
    return (bool) discovery_query("SELECT name FROM sqlite_master WHERE type='table' AND name=?", array($table))->fetchColumn();
}

function db_qstr($value, ...$arguments)
{
    return $GLOBALS['db']->quote((string) $value);
}
function db_execute($sql, ...$arguments)
{
    return $GLOBALS['db']->exec($sql) !== false;
}
