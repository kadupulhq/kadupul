<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace FloatWorkerSerializationTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/cli/float_rrdfiles.php'), 'float_master_handler'));
function float_reap_dead_children() { $GLOBALS['serialization_reaped'] = true; return 0; }
function db_table_exists($table) { return true; }
function db_execute(...$args) { return true; }
function db_execute_prepared(...$args) { return true; }
function db_fetch_cell($sql) { return $GLOBALS['serialization_launched'] ? 0 : 2; }
function db_fetch_cell_prepared($sql, $params) { expect($params)->toBe(array(1)); return 2; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function float_debug(...$args) {}
function cacti_log(...$args) {}
function unregister_process(...$args) {}
function float_launch_child($id, ...$args)
{
    $GLOBALS['serialization_launched'][] = $id;
    return fopen('php://memory', 'r+');
}
function proc_get_status($process) { return array('running' => false, 'exitcode' => 0, 'pid' => 42); }
function proc_close($process) { fclose($process); return 0; }

test('float master serializes requested parallel work and reaps stale registrations first', function ($requested) {
    $GLOBALS['serialization_launched'] = array();
    $GLOBALS['serialization_reaped'] = false;
    ob_start();
    try {
        expect(float_master_handler(false, false, false, false, false, array(), $requested, false, 1700000000, 1700000060))->toBeTrue();
        expect($GLOBALS['serialization_launched'])->toBe(array(1));
        expect($GLOBALS['serialization_reaped'])->toBeTrue();
    } finally {
        ob_end_clean();
    }
})->with(array(1, 20));
