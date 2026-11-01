<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// cli/replay_rejected_samples.php is a shim; the scope, help and version handling are covered by ReplayRejectedSamplesCommandTest and the
// parity scenarios. This is the worker that holds the collector and queue checks and calls poller_replay_rejected().
test('replay worker refuses an online remote collector and a volatile queue, and reports what it moved', function ($command, $exit, $call, $status, $engine = 'InnoDB', $collector = array(1, 'online')) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/replay-rejected-' . bin2hex(random_bytes(8));
    foreach (array('', '/bin', '/include', '/lib') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    copy($root . '/bin/legacy-rejected-samples-replay.php', $dir . '/bin/legacy-rejected-samples-replay.php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/bin/legacy-rejected-samples-replay.php', true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/bin/legacy-rejected-samples-replay.php', true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    file_put_contents($dir . '/include/cli_check.php', '<?php ' . $prelude . '$config = array("base_path" => dirname(__DIR__), "poller_id" => ' . (int) $collector[0] . ', "connection" => ' . var_export($collector[1], true) . '); define("COPYRIGHT_YEARS", "2026");'
        . 'function cacti_log($message, ...$args) { file_put_contents(dirname(__DIR__) . "/log", $message); }');
    // The move itself is covered by the database contract; this boundary records the request.
    file_put_contents($dir . '/lib/poller.php', '<?php function poller_replay_rejected($id = null, $dry = false) {'
        . 'file_put_contents(dirname(__DIR__) . "/call", json_encode(array($id, $dry))); return $id === 9 ? false : 3; }');
    // The queue check reads the engine of the connection replay writes to.
    file_put_contents($dir . '/lib/rrd_maintenance.php', '<?php function rrd_maintenance_queue_configuration_error($connection = false) {'
        . 'if ($connection !== false) { throw new LogicException("replay checked another queue"); }'
        . 'return ' . var_export($engine, true) . ' === "InnoDB" ? "" : "The poller_output queue must use InnoDB before collection."; }');
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/bin/legacy-rejected-samples-replay.php'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        fwrite($pipes[0], is_string($command) ? $command : json_encode($command));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe($exit, $output . $error);
        expect($error)->toBe('');
        expect(strstr($output, 'KADUPUL_REJECTED_SAMPLES_REPLAY_RESULT='))->toStartWith('KADUPUL_REJECTED_SAMPLES_REPLAY_RESULT={"status":"' . $status . '"');
        expect(is_file($dir . '/call') ? json_decode(file_get_contents($dir . '/call'), true) : null)->toBe($call);
        // A replay notes what it moved; a command the worker cannot read is logged by class only.
        $logged = $status === 'ok' && !$call[1]
            ? 'NOTE: Replayed 3 rejected samples for ' . ($call[0] === null ? 'all data sources' : 'Local Data ID ' . $call[0]) . ' into poller_output.'
            : ($status === 'failed' && $call === null ? 'ERROR: Rejected sample replay worker failed: ' . ($command === '{' ? 'JsonException' : 'RuntimeException') : null);
        expect(is_file($dir . '/log') ? file_get_contents($dir . '/log') : null)->toBe($logged);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/bin', '/include', '/lib', '') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array(
    'dry run' => array(array('local_data_id' => 5, 'dry_run' => true), 0, array(5, true), 'ok'),
    'all' => array(array('local_data_id' => null, 'dry_run' => false), 0, array(null, false), 'ok'),
    'one data source' => array(array('local_data_id' => 4, 'dry_run' => false), 0, array(4, false), 'ok'),
    'failure' => array(array('local_data_id' => 9, 'dry_run' => false), 1, array(9, false), 'failed'),
    'volatile queue' => array(array('local_data_id' => null, 'dry_run' => false), 1, null, 'unsafe_queue', 'MEMORY'),
    'volatile queue dry run' => array(array('local_data_id' => null, 'dry_run' => true), 0, array(null, true), 'ok', 'MEMORY'),
    'online remote collector' => array(array('local_data_id' => null, 'dry_run' => false), 1, null, 'remote_collector', 'InnoDB', array(2, 'online')),
    'online remote dry run' => array(array('local_data_id' => null, 'dry_run' => true), 1, null, 'remote_collector', 'InnoDB', array(2, 'online')),
    'offline remote collector' => array(array('local_data_id' => null, 'dry_run' => false), 0, array(null, false), 'ok', 'InnoDB', array(2, 'offline')),
    'a zero id' => array(array('local_data_id' => 0, 'dry_run' => false), 1, null, 'failed'),
    'a text id' => array(array('local_data_id' => '5', 'dry_run' => false), 1, null, 'failed'),
    'no scope key' => array(array('dry_run' => false), 1, null, 'failed'),
    'a text dry run flag' => array(array('local_data_id' => null, 'dry_run' => 'yes'), 1, null, 'failed'),
    'an unknown key' => array(array('local_data_id' => null, 'dry_run' => false, 'all' => true), 1, null, 'failed'),
    'not json' => array('{', 1, null, 'failed'),
));
