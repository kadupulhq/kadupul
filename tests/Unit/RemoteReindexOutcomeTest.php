<?php

declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RemoteReindexOutcomeTest;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Contract/CollectorAddress.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/data_query.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read data query source');
}
eval('namespace RemoteReindexOutcomeTest;' . \test_php_function_source($source, 'run_data_query_work'));
define(__NAMESPACE__ . '\\HOST_DOWN', 1);
function read_config_option($name)
{
    return '';
}
function db_column_exists($table, $column)
{
    return true;
}
function db_fetch_row_prepared($sql, $params = array())
{
    return array('status' => 3, 'disabled' => '', 'poller_id' => 2);
}
function db_fetch_cell_prepared($sql, $params = array())
{
    return '192.0.2.2';
}
function cacti_sizeof($v)
{
    return is_array($v) ? count($v) : false;
}
function get_url_type()
{
    return 'http';
}
function cacti_http(...$args)
{
    return $GLOBALS['remote_reindex_response'];
}
function debug_log_escape($line)
{
    return htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
}
function automation_execute_data_query($host, $query)
{
    $GLOBALS['remote_reindex_effects'][] = 'automation';
}
function api_plugin_hook_function($hook, $data)
{
    $GLOBALS['remote_reindex_effects'][] = $hook;
    return $data;
}
function query_debug_timer_offset(...$arguments) {}
function __esc($message, ...$arguments)
{
    return $message;
}

beforeEach(function () {
    $GLOBALS['config'] = array('poller_id' => 1, 'url_path' => '/monitor/');
    $GLOBALS['remote_reindex_effects'] = array();
    $_SESSION = array('debug_log' => array('response' => true));
});

test('remote reindex uses the received boolean and preserves collector diagnostics', function () {
    $GLOBALS['remote_reindex_response'] = json_encode(array('result' => true, 'data_query' => array('<script>bad</script>')));
    expect(run_data_query_work(1, 1))->toBeTrue()
        ->and($GLOBALS['remote_reindex_effects'])->toBe(array('automation','run_data_query'))
        ->and($_SESSION['debug_log']['data_query'][0])->toBe('<script>bad</script>')
        ->and(isset($_SESSION['debug_log']['response']))->toBeFalse();
});

test('remote failures cannot borrow an earlier session success', function ($body) {
    $GLOBALS['remote_reindex_response'] = $body;
    expect(run_data_query_work(1, 1))->toBeFalse()->and($GLOBALS['remote_reindex_effects'])->toBe(array());
})->with(array(
    'false result' => '{"result":false,"data_query":[]}',
    'empty response' => '', 'invalid JSON' => '{', 'null' => 'null', 'scalar' => '1',
    'missing result' => '{"data_query":[]}', 'missing log' => '{"result":true}',
    'scalar log' => '{"result":true,"data_query":"ok"}',
    'integer result' => '{"result":1,"data_query":[]}',
    'string result' => '{"result":"false","data_query":[]}',
    'array result' => '{"result":[],"data_query":[]}',
));


test('collector JSON uses a boolean for accepted plugin success values', function ($value) use ($source) {
    $block = \test_php_block_source($source, 'if (!isset($_SESSION))', 'function run_data_query_work');
    $previous = $_SESSION;
    unset($_SESSION);
    $config = array('debug_log' => array('data_query' => array()));
    $result = $value;
    ob_start();
    try {
        eval($block);
        $output = ob_get_contents();
    } finally {
        ob_end_clean();
        $_SESSION = $previous;
    }
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array('data_query' => array(), 'result' => true));
})->with(array(true, 1));
