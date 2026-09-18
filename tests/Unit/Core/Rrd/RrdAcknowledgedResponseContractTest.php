<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace RrdAcknowledgedResponseContract;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/lib/rrd.php');
foreach (array('rrd_acknowledged_pipes', 'rrd_command_deadline', 'rrd_acknowledged_command') as $name) {
    eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, $name));
}
function escape_command($command)
{
    return $command;
}
function cacti_log(...$args) {}
function hrtime($numeric)
{
    return array_shift($GLOBALS['response_clock']);
}
function stream_select(&$read, &$write, &$except, $seconds, $microseconds)
{
    if (++$GLOBALS['response_selects'] === 1) {
        $read = array();
    } else {
        $write = array();
    }
    return 1;
}
function proc_terminate($process)
{
    $GLOBALS['response_terminated'] = true;
    return true;
}

test('acknowledgement deadline honors longer configuration and bounds extreme values', function ($timeout, $elapsed, $expected) {
    $saved = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = array('rrd_command_timeout' => $timeout);
    $GLOBALS['response_clock'] = array(0, 100000000, $elapsed * 1000000000);
    $GLOBALS['response_selects'] = 0;
    $GLOBALS['response_terminated'] = false;
    $pipe = fopen('php://temp', 'r+');
    $read = fopen('php://temp', 'r+');
    fwrite($read, "OK u:0 s:0 r:0\n");
    rewind($read);
    $pipes = & rrd_acknowledged_pipes();
    $pipes[(int) $pipe] = array('read' => $read, 'failed' => false, 'process' => null);
    try {
        list($result, $output) = rrd_acknowledged_command($pipe, 'dump fixture.rrd');
        expect($result)->toBe($expected)
            ->and($GLOBALS['response_terminated'])->toBe(!$expected)
            ->and($pipes[(int) $pipe]['failed'])->toBe(!$expected);
        expect($output)->toBe($expected ? "OK u:0 s:0 r:0\n" : '');
    } finally {
        unset($pipes[(int) $pipe]);
        fclose($pipe);
        fclose($read);
        $GLOBALS['config'] = $saved;
    }
})->with(array(array(60, 61, false), array(120, 61, true), array(0, 2, false), array(7200, 3601, false)));
