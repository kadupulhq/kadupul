<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Native alerting reads the rates dsstats_poller_output() writes (ADR 0011).
 * This runs the production process_poller_output_batch() with Boost on and
 * off and shows the poller hands every complete sample to dsstats either way:
 * Boost changes who writes the RRD, not whether dsstats sees the sample.
 */

test('the poller hands samples to dsstats whether or not Boost is on', function (bool $boost) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/boost-dsstats-' . bin2hex(random_bytes(8));
    foreach (array('', '/lib', '/include') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    foreach (array('rrd', 'poller', 'data_query') as $lib) {
        file_put_contents($dir . '/lib/' . $lib . '.php', '<?php');
    }
    file_put_contents($dir . '/user_1_1.rrd', 'fixture');
    file_put_contents($dir . '/run.php', '<?php require ' . var_export($root . '/tests/Fixtures/poller-ack-bootstrap.php', true) . ';'
        . 'require ' . var_export($root . '/lib/poller.php', true) . ';'
        . '$deferred=false;$proxy=false;$result=process_poller_output_batch($deferred,$proxy);'
        . 'file_put_contents(getenv("ACK_FIXTURE")."/batch-result.json",json_encode(array($result,$deferred)));db_close();exit($deferred?1:0);');
    try {
        $process = proc_open(array(PHP_BINARY, $dir . '/run.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('ACK_FIXTURE' => $dir, 'ACK_REALTIME' => '0', 'ACK_PROXY' => '0', 'ACK_FAIL' => '0', 'ACK_BOOST' => $boost ? '1' : '0')));
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe(0, $error . $output)->and($error)->toBe('');

        $events = array_map(static fn($line) => json_decode($line, true), file($dir . '/dsstats_poller_output.jsonl', FILE_IGNORE_NEW_LINES));
        expect($events)->toHaveCount(1);
        expect($events[0]['fixture.rrd']['local_data_id'])->toBe(1)
            ->and($events[0]['fixture.rrd']['times'])->toBe(array((string) strtotime('2020-01-01') => array('value' => '42')));
        // Boost writes the RRD later from its own queue; without Boost the poller writes it now.
        expect(file_exists($dir . '/updates.json'))->toBe(!$boost);
        // The sample was acknowledged and leaves poller_output in both modes.
        expect(json_decode(file_get_contents($dir . '/outcome.json'), true))->toBe($boost ? array() : array('43'));
    } finally {
        foreach (array('/include', '/lib', '') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array('Boost on' => array(true), 'Boost off' => array(false)));
