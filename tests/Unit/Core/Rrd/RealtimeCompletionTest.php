<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace RealtimeCompletionTest;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/poller_realtime.php'), 'process_poller_output_rt')); // nosemgrep: php.lang.security.eval-use.eval-use
function cacti_log(...$args) {}
function cacti_log_safe_value($value) { return json_encode($value); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function read_config_option($key) { return $GLOBALS['rt_fixture']; }
function db_fetch_assoc_prepared(...$args) { return $GLOBALS['rt_rows']; }
function db_execute_prepared($sql, $params) { $GLOBALS['rt_deleted'][] = $params; return true; }
function get_data_source_path($id, ...$args) { return $id === 7 ? "invalid\npath" : $GLOBALS['rt_fixture'] . '/source.rrd'; }
function cacti_rrdtool_valid_path($path) { return strpos($path, "\n") === false; }
function rrdtool_function_create(...$args) { return false; }
function rrdtool_function_update($updates, $pipe, &$completed) {
    $completed = array();
    foreach ($updates as $path => $update) {
        foreach ($update['times'] as $time => $values) { $completed[$path][$time] = true; }
    }
    return count($updates);
}

test('realtime preparation failures retain pending samples and cannot report success', function ($mode) {
    $saved = $GLOBALS['config'] ?? null;
    $dir = sys_get_temp_dir() . '/rt-completion-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents($dir . '/rrd.php', '<?php');
    file_put_contents($dir . '/user_1_8.rrd', 'existing');
    $GLOBALS['rt_fixture'] = $dir;
    $GLOBALS['config'] = array('library_path' => $dir);
    $ids = array('invalid' => array(7), 'mixed' => array(7, 8), 'valid' => array(8), 'create' => array(9), 'query' => array())[$mode];
    $GLOBALS['rt_rows'] = $mode === 'query' ? false : array_map(fn($id) => array('local_data_id' => $id, 'output' => '42', 'time' => '2026-09-15 00:00:00', 'rrd_name' => 'value'), $ids);
    $GLOBALS['rt_deleted'] = array();
    try {
        expect(process_poller_output_rt(true, 1, 60))->toBe($mode === 'valid' ? 1 : false);
        $deleted = $GLOBALS['rt_deleted'];
        expect(array_column($deleted, 0))->toBe(in_array($mode, array('valid', 'mixed'), true) ? array(8) : array());
        if ($deleted) { expect($deleted[0])->toBe(array(8, 'value', '2026-09-15 00:00:00', 1, '42')); }
    } finally {
        $GLOBALS['config'] = $saved;
        unlink($dir . '/rrd.php'); unlink($dir . '/user_1_8.rrd'); rmdir($dir);
    }
})->with(array('invalid', 'mixed', 'valid', 'create', 'query'));
