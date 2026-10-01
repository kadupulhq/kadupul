<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
$root = getenv('DISCOVERY_TEST_ROOT');
$directory = getenv('DISCOVERY_TEST_DIRECTORY');
$case = getenv('DISCOVERY_TEST_CASE');
$config = array('base_path' => $root,'include_path' => $root . '/include','poller_id' => 1,'is_web' => false,'config_options_array' => array('settings_smtp_timeout' => '5','settings_how' => '1','settings_sendmail_path' => escapeshellarg($directory . '/sendmail'),'settings_wordwrap' => '76','settings_from_name' => '','selective_debug' => '','client_timezone_support' => '','path_cactilog' => $directory . '/audit.log','log_destination' => '1','log_verbosity' => '5','automation_fromname' => '','automation_fromemail' => '','settings_from_email' => '','automation_email' => '','admin_user' => ''));
$mail_methods = array(1 => 'inert capture');
$cacti_locale = 'en-US';
require $root . '/include/global_constants.php';
require $root . '/lib/functions.php';
require $root . '/lib/html.php';
function __($message, ...$arguments)
{
    return $arguments ? vsprintf($message, $arguments) : $message;
}
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE version (cacti TEXT)');
$db->exec("INSERT INTO version VALUES ('test')");
$db->exec('CREATE TABLE automation_networks (id INTEGER PRIMARY KEY,name TEXT,subnet_range TEXT,last_started TEXT,last_runtime INTEGER,notification_enabled TEXT,notification_email TEXT,notification_fromname TEXT,notification_fromemail TEXT)');
$db->exec('CREATE TABLE automation_devices (id INTEGER PRIMARY KEY,network_id INTEGER,hostname TEXT,ip TEXT,sysName TEXT,snmp INTEGER,up INTEGER,time TEXT)');
$network = array(7,'<b>Network</b>','<SUBJECT>/24',null,12,'on','recipient@example.invalid','Sender','sender@example.invalid');
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
$names = array('markup' => '<script>alert(1)</script>','placeholder' => '<SUBJECT>','empty' => '','null' => null,'entity' => '&lt;already&gt;');
$name = array_key_exists($case, $names) ? $names[$case] : 'normal';
$db->prepare('INSERT INTO automation_devices VALUES (?,?,?,?,?,?,?,?)')->execute(array(1,7,'host"`<b>x</b>','<TO>',$name,1,0,''));
function discovery_query($sql, $params = array())
{
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
register_shutdown_function(static function () use ($case, $directory) {
    ob_clean();
    $old = $case === 'existing' ? getNetworkDevices(7) : array();
    reportNetworkStatus(7, $old);
    file_put_contents($directory . '/state.json', json_encode(array('log' => is_file($directory . '/audit.log') ? file_get_contents($directory . '/audit.log') : '','sent' => is_file($directory . '/message')), JSON_THROW_ON_ERROR));
    ob_end_clean();
});
if (getenv('DISCOVERY_TEST_COVERAGE') === '1') {
    define('RRD_TEST_COVERAGE_DIRECTORY', $directory);
    define('RRD_TEST_CLI_COVERAGE_COPY', $directory . '/poller_automation.php');
    define('RRD_TEST_CLI_COVERAGE_SOURCE', $root . '/poller_automation.php');
    require $root . '/tests/Fixtures/rrd-process-coverage.php';
}
ob_start();
