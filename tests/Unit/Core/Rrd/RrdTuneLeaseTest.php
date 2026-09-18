<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 4) . '/lib/rrd_maintenance.php';

test('tuning gives up behind a busy writer instead of blocking the request', function ($busy) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/rrd-tune-lease-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    file_put_contents($dir . '/source.rrd', 'fixture');
    file_put_contents($dir . '/global_arrays.php', '<?php $data_source_types = array(1 => "GAUGE");');
    // The RRDtool boundary only records that it ran.
    file_put_contents($dir . '/rrdtool', "#!/bin/sh\ntouch " . escapeshellarg($dir . '/tuned') . "\n");
    chmod($dir . '/rrdtool', 0700);
    $config = array('cacti_server_os' => 'unix', 'rra_path' => $dir, 'include_path' => $dir, 'is_web' => true);
    $tune = array('data_source_id' => 1, 'data-source-type' => 1, 'heartbeat' => 777, 'minimum' => '', 'maximum' => '', 'data-source-rename' => '');
    file_put_contents($dir . '/tune.php', '<?php $config = ' . var_export($config, true) . ';' .
        'define("CACTI_LOCALE", "en-US"); define("POLLER_VERBOSITY_DEBUG", 5);' .
        'function read_config_option($name) { return $name === "path_rrdtool" ? __DIR__ . "/rrdtool" : ""; }' .
        'function cacti_log($message, ...$args) { file_put_contents(__DIR__ . "/log", $message . PHP_EOL, FILE_APPEND); }' .
        'function cacti_escapeshellcmd($value) { return escapeshellcmd($value); } function cacti_escapeshellarg($value) { return escapeshellarg($value); }' .
        'function get_data_source_item_name($id) { return "value"; } function get_data_source_path($id, $expand) { return __DIR__ . "/source.rrd"; }' .
        'require ' . var_export($root . '/lib/rrd.php', true) . ';' .
        '$start = microtime(true); $result = rrdtool_function_tune(' . var_export($tune, true) . ');' .
        'echo json_encode(array($result, microtime(true) - $start));');
    $saved = $GLOBALS['config'] ?? null;
    $GLOBALS['config'] = $config;
    $writer = $busy ? rrd_maintenance_acquire() : false;
    try {
        $process = proc_open(array(PHP_BINARY, $dir . '/tune.php'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        // An unbounded wait would hang the suite, so stop the child after 20 seconds.
        $deadline = microtime(true) + 20;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50000);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Tuning blocked behind the maintenance lease');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        // proc_close() cannot report an exit code already collected by proc_get_status().
        proc_close($process);
        expect($status['exitcode'])->toBe(0, $error)->and($error)->toBe('');
        list($result, $elapsed) = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($busy) {
            expect($result)->toBeFalse()
                ->and($elapsed)->toBeLessThan(15)
                ->and(file_exists($dir . '/tuned'))->toBeFalse()
                ->and(file_get_contents($dir . '/log'))->toContain('RRD storage is busy');
        } else {
            expect($result)->not->toBeFalse()->and(file_exists($dir . '/tuned'))->toBeTrue();
        }
    } finally {
        rrd_maintenance_release($writer);
        $GLOBALS['config'] = $saved;
        foreach (glob($dir . '/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
})->with(array('busy' => true, 'idle' => false));
