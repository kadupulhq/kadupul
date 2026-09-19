<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('failed queue inspection stops before producer launch while empty queues proceed', function ($issues, $status) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    $start = strpos($source, '// A failed inspection');
    $end = strpos($source, 'if (cacti_sizeof($issues))', $start);
    $code = 'function cacti_log($message, ...$args) {echo $message;} $issues=' . var_export($issues, true) . ';'
        . substr($source, $start, $end - $start) . 'echo "PRODUCER_STARTED";';
    $process = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe($status)->and($error)->toBe('');
    if ($issues === false) {
        expect($output)->toContain('Unable to inspect')->not->toContain('PRODUCER_STARTED');
    } else {
        expect($output)->toBe('PRODUCER_STARTED');
    }
})->with(array(array(false, 1), array(array(), 0), array(array(array('local_data_id' => 1)), 0)));
