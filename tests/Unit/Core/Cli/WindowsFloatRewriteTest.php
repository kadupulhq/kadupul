<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace WindowsFloatRewriteTest;
require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';
function fwrite($stream, $message) { $GLOBALS['float_refusal'] .= $message; return strlen($message); }
function float_rrdfile(...$args) { throw new \RuntimeException('Unsupported Windows rewrite was invoked'); }
function db_execute_prepared(...$args) { throw new \RuntimeException('Pending Windows rewrite was removed'); }

test('Windows float worker refuses an uncoordinated rewrite and retains its queued request', function () {
    $old = $GLOBALS['config'] ?? array();
    $GLOBALS['config'] = array('cacti_server_os' => 'win32');
    $GLOBALS['float_refusal'] = '';
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/float_rrdfiles.php');
    $start = strpos($source, '$rrd_rewrite_lock = rrd_maintenance_acquire(');
    $end = strpos($source, 'rrd_maintenance_release($rrd_rewrite_lock);', $start);
    $end = strpos($source, '}', $end) + 1;
    $data = array('rrd_path' => 'keep.rrd', 'local_data_id' => 1);
    $step = $start_time = $end_time = 0;
    $exit_status = 0;
    try {
        eval('namespace ' . __NAMESPACE__ . '; do {' . substr($source, $start, $end - $start) . '} while (false);');
        expect($exit_status)->toBe(1)->and($GLOBALS['float_refusal'])->toContain('maintenance lock is unavailable');
    } finally {
        $GLOBALS['config'] = $old;
    }
});
