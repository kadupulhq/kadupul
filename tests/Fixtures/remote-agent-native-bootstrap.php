<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$mode = getenv('REMOTE_AGENT_TEST_MODE');
$config = array('base_path' => getenv('REMOTE_AGENT_TEST_DIRECTORY'), 'poller_id' => str_contains($mode, 'collector') ? 2 : 1, 'connection' => 'offline');
$config['url_path'] = '/';
define('FILTER_VALIDATE_MAX_DATE_AS_INT', 2147483647);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE host (id INTEGER, poller_id INTEGER);
    INSERT INTO host VALUES (10,2),(11,1),(12,3);
    CREATE TABLE graph_local (id INTEGER, host_id INTEGER);
    INSERT INTO graph_local VALUES (20,10),(21,0);
    CREATE TABLE graph_templates_item (local_graph_id INTEGER,task_item_id INTEGER);
    INSERT INTO graph_templates_item VALUES (20,30);
    CREATE TABLE data_template_rrd (id INTEGER,local_data_id INTEGER);
    INSERT INTO data_template_rrd VALUES (30,40);
    CREATE TABLE data_local (id INTEGER,host_id INTEGER);
    INSERT INTO data_local VALUES (40,10);
    CREATE TABLE user_auth (id INTEGER,enabled TEXT,locked TEXT);
    INSERT INTO user_auth VALUES (7,"on",""),(8,"","");
    CREATE TABLE automation_networks (id INTEGER,poller_id INTEGER);
    INSERT INTO automation_networks VALUES (50,1),(51,2);');
if ($mode === 'graph-mixed') {
    $db->exec('INSERT INTO data_template_rrd VALUES (31,41); INSERT INTO data_local VALUES (41,12); INSERT INTO graph_templates_item VALUES (20,31)');
}
if ($mode === 'graph-orphan') {
    $db->exec('INSERT INTO graph_templates_item VALUES (20,99)');
}
if ($mode === 'graph-empty') {
    $db->exec('DELETE FROM graph_templates_item');
}
if ($mode === 'graph-hostless-data') {
    $db->exec('UPDATE data_local SET host_id=0');
}
$request = array('action' => 'graph_json', 'local_graph_id' => 20, 'rra_id' => 1, 'effective_user' => 7,
    'graph_start' => 1700000000, 'graph_end' => 1700003600, 'graph_height' => 120, 'graph_width' => 240,
    'graph_nolegend' => 'true', 'show_source' => '1', 'disable_cache' => '1', 'graph_theme' => 'modern');
if ($mode === 'graph-image-collector') {
    $request['image_format'] = 'png';
    $_SESSION = array('sess_user_id' => 7);
}
if (getenv('REMOTE_AGENT_TEST_REQUEST')) {
    $request = json_decode(getenv('REMOTE_AGENT_TEST_REQUEST'), true, 512, JSON_THROW_ON_ERROR);
}
if ($mode === 'graph-denied') {
    $request['effective_user'] = 9;
}
if ($mode === 'graph-disabled') {
    $request['effective_user'] = 8;
}
if ($mode === 'graph-no-user') {
    unset($request['effective_user']);
}
if ($mode === 'graph-hostless') {
    $request['local_graph_id'] = 21;
}
if ($mode === 'graph-missing') {
    $request['local_graph_id'] = 99;
}
$actions = array('ping' => 'ping', 'query' => 'runquery', 'snmp' => 'snmpget', 'walk' => 'snmpwalk', 'poll' => 'polldata');
$prefix = strtok($mode, '-');
if (isset($actions[$prefix])) {
    $request['action'] = $actions[$prefix];
    $request['host_id'] = str_contains($mode, 'denied') ? 12 : ($config['poller_id'] === 2 ? 10 : 11);
    $request['data_query_id'] = 5;
}
$calls = array();
if (str_starts_with($mode, 'discover-')) {
    $request['action'] = 'discover';
    $request['network'] = str_contains($mode, 'denied') ? 99 : ($config['poller_id'] === 2 ? 51 : 50);
    if (str_contains($mode, 'all')) {
        $request['network'] = 0;
    }
}
function get_client_addr()
{
    return $GLOBALS['mode'] === 'unauthorized' ? false : (str_contains($GLOBALS['mode'], 'main-broker') ? '127.0.0.1' : '127.0.0.2');
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {}
function db_fetch_assoc(...$args)
{
    return array(array('id' => 1, 'hostname' => '127.0.0.1'), array('id' => $GLOBALS['config']['poller_id'] === 2 ? 3 : 2, 'hostname' => '127.0.0.2'));
}
function native_query($sql, $params)
{
    $stmt = $GLOBALS['db']->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}
function db_fetch_cell_prepared($sql, $params)
{
    return native_query($sql, $params)->fetchColumn();
}
function db_fetch_row_prepared($sql, $params)
{
    return native_query($sql, $params)->fetch(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared($sql, $params)
{
    return native_query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, ...$args)
{
    $value = $GLOBALS['request'][$name] ?? 0;
    return $filter === FILTER_VALIDATE_INT && is_numeric($value) ? (int) $value : $value;
}
function get_nfilter_request_var($name)
{
    return $GLOBALS['request'][$name] ?? '';
}
function get_request_var($name)
{
    return get_nfilter_request_var($name);
}
function set_request_var($name, $value)
{
    $GLOBALS['request'][$name] = $value;
}
function isset_request_var($name)
{
    return array_key_exists($name, $GLOBALS['request']);
}
function isempty_request_var($name)
{
    return empty($GLOBALS['request'][$name]);
}
function set_default_action() {}
function is_graph_allowed($graph, $user)
{
    $GLOBALS['calls'][] = array('permission', $graph, $user);
    return $user === 7 && $GLOBALS['mode'] !== 'graph-no-permission';
}
function cacti_validate_theme($theme)
{
    return $theme;
}
function rrdtool_function_graph($graph, $rra, $options, $unused, &$xport, $user)
{
    $GLOBALS['calls'][] = array('render', $graph, $user);
    return (getenv('REMOTE_AGENT_TEST_RAW_IMAGE') ? "image = PNG\n" : '') . 'GRAPH IMAGE';
}
function api_device_ping_device($host, $remote)
{
    $GLOBALS['calls'][] = array('ping', $host);
}
function run_data_query($host, $query)
{
    $GLOBALS['calls'][] = array('query', $host, $query);
}
function read_config_option($key)
{
    return $key === 'script_timeout' ? 30 : '';
}
function api_plugin_hook_function(...$args)
{
    return false;
}
function cacti_session_close() {}
function cacti_escapeshellarg($value)
{
    return escapeshellarg((string) $value);
}
function cacti_escapeshellcmd($value)
{
    return escapeshellcmd((string) $value);
}
function exec_background($command, $options)
{
    $GLOBALS['calls'][] = array('discover', $command, $options);
}
function call_remote_data_collector($poller, $url)
{
    parse_str(parse_url($url, PHP_URL_QUERY), $request);
    if ($poller !== 1 || ($request['effective_user'] ?? null) !== '7') {
        throw new RuntimeException('Graph caller omitted its authenticated user');
    }
    $process = proc_open(
        array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $GLOBALS['config']['base_path'] . '/remote_agent.php'),
        array(1 => array('pipe','w'), 2 => array('pipe','w')),
        $pipes,
        null,
        array_merge(getenv(), array('REMOTE_AGENT_TEST_MODE' => 'graph-allowed', 'REMOTE_AGENT_TEST_REQUEST' => json_encode($request), 'REMOTE_AGENT_TEST_RAW_IMAGE' => '1'))
    );
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $error !== '') {
        throw new RuntimeException('Native graph receiver failed: ' . $error);
    }
    list($body, $json) = explode("\nRESULT:", $output);
    $GLOBALS['calls'] = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['calls'];
    return $body;
}
register_shutdown_function(function () {
    echo "\nRESULT:" . json_encode(array('calls' => $GLOBALS['calls'], 'identity' => $GLOBALS['remote_agent_authorized_poller_id'] ?? 0), JSON_THROW_ON_ERROR);
});
