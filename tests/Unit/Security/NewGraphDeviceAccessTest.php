<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('graph creation is refused for devices the user cannot access', function ($host_id, $expected) {
    $root = dirname(__DIR__, 3);
    $program = 'require ' . var_export($root . '/lib/template.php', true) . ';$host_id=' . var_export($host_id, true) . ';';
    $program .= <<<'PROBE'
function is_device_allowed($id) { return $id === 12; }
function cacti_log($message, ...$args) { echo 'LOG:' . $message; }
// Past the guard, stop before graph creation touches the database.
function input_validate_input_number($value) { echo 'CREATE'; exit; }
var_export(create_save_graph($host_id, 'cg', 5, array(), array()));
PROBE;
    $process = proc_open(array(PHP_BINARY, '-r', $program), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($error)->toBe('')
        ->and($output)->toBe($expected);
})->with(array(
    'allowed device' => array(12, 'CREATE'),
    'foreign device' => array(13, 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
    'no device' => array(0, 'LOG:WARNING: Graph creation rejected for a device the current user cannot access.false'),
));
