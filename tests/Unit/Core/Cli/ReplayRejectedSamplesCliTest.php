<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('replay CLI requires an explicit scope and reports what it moved', function ($arguments, $exit, $call, $message, $engine = 'InnoDB', $collector = array(1, 'online')) {
    $root = dirname(__DIR__, 4);
    $dir = sys_get_temp_dir() . '/replay-rejected-' . bin2hex(random_bytes(8));
    foreach (array('', '/cli', '/include', '/lib') as $suffix) {
        mkdir($dir . $suffix, 0700);
    }
    copy($root . '/cli/replay_rejected_samples.php', $dir . '/cli/replay_rejected_samples.php');
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $prelude = '';
    if ($coverage !== null) {
        $prelude = 'define("RRD_TEST_COVERAGE_DIRECTORY",' . var_export($dir, true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_COPY",' . var_export($dir . '/cli/replay_rejected_samples.php', true) . ');'
            . 'define("RRD_TEST_CLI_COVERAGE_SOURCE",' . var_export($root . '/cli/replay_rejected_samples.php', true) . ');'
            . 'require ' . var_export($root . '/tests/Fixtures/rrd-process-coverage.php', true) . ';';
    }
    file_put_contents($dir . '/include/cli_check.php', '<?php ' . $prelude . '$config = array("base_path" => dirname(__DIR__), "poller_id" => ' . (int) $collector[0] . ', "connection" => ' . var_export($collector[1], true) . '); define("COPYRIGHT_YEARS", "2026");'
        . 'function get_cacti_cli_version() { return "fixture"; }'
        . 'function cacti_log($message, ...$args) { file_put_contents(dirname(__DIR__) . "/log", $message); }');
    // The move itself is covered by the database contract; this boundary records the request.
    file_put_contents($dir . '/lib/poller.php', '<?php function poller_replay_rejected($id = null, $dry = false) {'
        . 'file_put_contents(dirname(__DIR__) . "/call", json_encode(array($id, $dry))); return $id === 9 ? false : 3; }');
    // The queue check reads the engine of the connection replay writes to.
    file_put_contents($dir . '/lib/rrd_maintenance.php', '<?php function rrd_maintenance_queue_configuration_error($connection = false) {'
        . 'if ($connection !== false) { throw new LogicException("replay checked another queue"); }'
        . 'return ' . var_export($engine, true) . ' === "InnoDB" ? "" : "The poller_output queue must use InnoDB before collection."; }');
    try {
        $process = proc_open(array_merge(array(PHP_BINARY, '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $dir . '/cli/replay_rejected_samples.php'), $arguments), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        expect(proc_close($process))->toBe($exit, $output)->and($output)->toContain($message);
        expect(is_file($dir . '/call') ? json_decode(file_get_contents($dir . '/call'), true) : null)->toBe($call);
        expect(is_file($dir . '/log'))->toBe($exit === 0 && $call !== null && !$call[1]);
        if ($coverage !== null) {
            $reports = glob($dir . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $coverage->merge(unserialize(file_get_contents($reports[0])));
        }
    } finally {
        foreach (array('/cli', '/include', '/lib', '') as $suffix) {
            foreach (glob($dir . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir . $suffix);
        }
    }
})->with(array(
    'no scope' => array(array(), 1, null, 'Specify exactly one of --local-data-id=N or --all'),
    'both scopes' => array(array('--all', '--local-data-id=5'), 1, null, 'Specify exactly one'),
    'invalid id' => array(array('--local-data-id=x'), 1, null, 'requires a positive integer'),
    'dry run' => array(array('--local-data-id=5', '--dry-run'), 0, array(5, true), 'Would replay 3 rejected samples for Local Data ID 5'),
    'all' => array(array('--all'), 0, array(null, false), 'Replayed 3 rejected samples for all data sources'),
    'failure' => array(array('--local-data-id=9'), 1, array(9, false), 'Unable to replay rejected samples'),
    'volatile queue' => array(array('--all'), 1, null, 'Rejected samples were not replayed. The poller_output queue must use InnoDB', 'MEMORY'),
    'help' => array(array('--help'), 0, null, 'usage: replay_rejected_samples.php'),
    'version' => array(array('--version'), 0, null, 'Kadupul Rejected Sample Replay Utility, Version fixture'),
    'unknown parameter' => array(array('--bogus'), 1, null, 'ERROR: Invalid Parameter --bogus'),
    'volatile queue dry run' => array(array('--all', '--dry-run'), 0, array(null, true), 'Would replay 3', 'MEMORY'),
    'online remote collector' => array(array('--all'), 1, null, 'Run replay_rejected_samples.php on the main data collector', 'InnoDB', array(2, 'online')),
    'online remote dry run' => array(array('--all', '--dry-run'), 1, null, 'queues samples in the main database', 'InnoDB', array(2, 'online')),
    'offline remote collector' => array(array('--all'), 0, array(null, false), 'Replayed 3 rejected samples', 'InnoDB', array(2, 'offline')),
));
