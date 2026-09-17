<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace DsdebugRepairResultTest;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/dsdebug.php'), 'dsdebug_run_repair'));
function db_fetch_row_prepared(...$args) { return array('info' => array('rrd_match_array' => array('tune' => array('fixture --minimum value:0')))); }
function cacti_sizeof($value) { return count($value); }
function cacti_unserialize($value) { return $value; }
function get_data_source_path(...$args) { return $GLOBALS['repair_file']; }
function read_config_option($key) { return 'rrdtool'; }
function rrdtool_execute(...$args) { return $GLOBALS['repair_result']; }
function cacti_log($message, ...$args) { $GLOBALS['repair_logs'][] = $message; }
if (!defined('RRDTOOL_OUTPUT_RETURN_STDERR')) { define('RRDTOOL_OUTPUT_RETURN_STDERR', 5); }

test('repair reports success only for acknowledged empty stderr', function ($result, $success) {
    $GLOBALS['repair_file'] = tempnam(sys_get_temp_dir(), 'repair-');
    $GLOBALS['repair_result'] = $result;
    $GLOBALS['repair_logs'] = array();
    try {
        expect(dsdebug_run_repair(8))->toBe($success)
            ->and($GLOBALS['repair_logs'][0])->toContain($success ? 'command succeeded' : 'command failed');
    } finally {
        unlink($GLOBALS['repair_file']);
        unset($GLOBALS['repair_file'], $GLOBALS['repair_result'], $GLOBALS['repair_logs']);
    }
})->with(array(array(false, false), array('', true), array('ERROR: rejected', false)));
