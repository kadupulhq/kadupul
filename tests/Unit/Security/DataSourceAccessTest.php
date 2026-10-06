<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('data source access follows the owning device', function ($row, $allowed) {
    $root = dirname(__DIR__, 3);
    $program = '$row=' . var_export($row, true) . ';require ' . var_export($root . '/lib/api_data_source.php', true) . ';';
    $program .= <<<'PROBE'
function db_fetch_row_prepared(...$args) { return $GLOBALS['row']; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function is_device_allowed($host_id) { return $host_id === 12; }
echo json_encode(api_data_source_is_allowed(8));
PROBE;
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($error)->toBe('')
        ->and(json_decode($output, true))->toBe($allowed);
})->with(array(
    'allowed device' => array(array('host_id' => '12'), true),
    'foreign device' => array(array('host_id' => '13'), false),
    'missing data source' => array(array(), false),
    'no device' => array(array('host_id' => '0'), true),
    'invalid negative device' => array(array('host_id' => '-1'), false),
));
