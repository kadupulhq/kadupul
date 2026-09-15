<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('the production drain exits on a stalled queue instead of spinning', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/poller_output_empty.php');
    $start = strpos($source, 'while (');
    $end = strpos($source, '/*  display_version', $start);
    expect($start)->not->toBeFalse();
    expect($end)->not->toBeFalse();
    $code = 'set_time_limit(3); function db_fetch_cell($sql) { return 2; }'
        . 'function process_poller_output(&$pipe, $remainder, &$deferred, &$consumed) { $deferred=true; $consumed=0; return 0; }'
        . 'function rrd_close($pipe) { echo "CLOSED\\n"; }'
        . '$rrds_processed=0; $rrdtool_pipe=null;'
        . substr($source, $start, $end - $start);
    $pipes = array();
    $child = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($child))->toBe(1)
        ->and($stdout)->toContain('made no progress')->toContain('CLOSED')
        ->and($stderr)->toBe('');
});


test('concurrent arrivals do not stop a drain that acknowledged source deletions', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/poller_output_empty.php');
    $start = strpos($source, 'while (');
    $end = strpos($source, '/*  display_version', $start);
    // Cardinality stays at two for two passes as collectors replace consumed rows.
    $code = 'set_time_limit(3); $calls=0; function db_fetch_cell($sql) { global $calls; return $calls < 2 ? 2 : 0; }'
        . 'function process_poller_output(&$pipe, $remainder, &$deferred, &$consumed) { global $calls; $calls++; $deferred=false; $consumed=2; return 0; }'
        . 'function rrd_close($pipe) { echo "CLOSED\\n"; }'
        . '$rrds_processed=0; $rrdtool_pipe=null;'
        . substr($source, $start, $end - $start);
    $child = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    expect(proc_close($child))->toBe(0)
        ->and($stdout)->not->toContain('made no progress')
        ->and($stdout)->toContain('CLOSED')
        ->and($stderr)->toBe('');
});
