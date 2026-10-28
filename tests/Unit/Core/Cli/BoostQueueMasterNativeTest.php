<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/**
 * Runs the real master block of poller_boost.php in a child PHP process. The
 * status table, the clock and the consumer are in-memory stand-ins; the state
 * file carries them from one run to the next.
 *
 * @param array{mode?: string, poller_id?: int, consumer?: string, dispatch?: string, at?: int, signal?: bool, newer?: string, signalOnPublish?: bool} $scenario
 *
 * @return array{state: array, status: int, error: string}
 */
function boost_queue_master_run(string $stateFile, array $scenario): array
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller_boost.php');
    $start = strpos($source, 'if ($child == false) {');
    $end = strpos($source, "} else {\n    cacti_log('INFO: Boost register child process", $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $master = substr($source, $start, $end - $start) . '}';
    $bootstrap = <<<'FIXTURE'
<?php
namespace BoostQueueMasterProbe;
$stateFile = getenv('BOOST_STATE');
$scenario = json_decode(getenv('BOOST_SCENARIO'), true);
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : array('rows' => array(), 'options' => array(), 'log' => array(), 'effects' => array(), 'archiveWrites' => 0, 'sleeps' => array());
$state['dispatched'] = array(); $state['activeAtDispatch'] = null; $state['log'] = array(); $state['effects'] = array(); $state['sleeps'] = array();
$clock = 0;
$child = false; $forcerun = true; $rrd_updates = -1; $run_failed = false;
$config = array('poller_id' => $scenario['poller_id'] ?? 1);
$state['options']['boost_last_run_time'] ??= 1234;
function active_run() { return $GLOBALS['state']['options']['boost_active_run'] ?? ''; }
function consume() {
    // A consumer that is up: reports every partition of the run the master is waiting on.
    $run = active_run();
    $consumer = $GLOBALS['scenario']['consumer'] ?? 'none';
    // 'last-second' reports in the sleep that reaches the 3 s deadline (clock 7 + 3).
    if ($run !== '' && $consumer === 'one') { $GLOBALS['state']['rows'][$run][1] = 5; }
    if ($run !== '' && ($consumer === 'ok' || ($consumer === 'last-second' && $GLOBALS['clock'] >= 10))) {
        foreach ($GLOBALS['state']['dispatched'] as $partition) {
            $GLOBALS['state']['rows'][$run][$partition] = 5;
        }
    }
}
function sleep($seconds) {
    $GLOBALS['clock'] += $seconds; $GLOBALS['state']['sleeps'][] = $seconds;
    $at = $GLOBALS['scenario']['at'] ?? null;
    if ($at !== null && $GLOBALS['clock'] === $at) {
        if (isset($GLOBALS['scenario']['newer'])) { $GLOBALS['state']['options']['boost_active_run'] = $GLOBALS['scenario']['newer']; }
        if (!empty($GLOBALS['scenario']['signal'])) { sig_handler(SIGTERM); }
    }
    consume();
    return 0;
}
function hrtime($asNumber = false) { return $GLOBALS['clock'] * 1000000000; }
function read_config_option($key, $force = false) {
    switch ($key) {
        case 'boost_processing_mode': return $GLOBALS['scenario']['mode'] ?? 'children';
        case 'boost_parallel': return 2;
        case 'boost_rrd_update_max_runtime': return 1;
        case 'boost_rrd_update_interval': return 120;
        case 'boost_last_run_time': return $GLOBALS['state']['options']['boost_last_run_time'];
        case 'boost_active_run': return $GLOBALS['state']['options']['boost_active_run'] ?? false;
        default: return 1;
    }
}
function set_config_option($key, $value) {
    $GLOBALS['state']['options'][$key] = $value;
    // A SIGTERM that lands right after the run id is published.
    if (!empty($GLOBALS['scenario']['signalOnPublish']) && $key === 'boost_active_run' && $value !== '') { sig_handler(SIGTERM); }
}
function cacti_sizeof($rows) { return is_array($rows) ? count($rows) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['state']['log'][] = $message; }
function boost_debug(...$args) {}
function boost_time_to_run(...$args) { return true; }
function register_process_start(...$args) { return true; }
function unregister_process(...$args) {}
function boost_kill_running_processes() {}
function boost_prepare_process_table() { return true; }
function boost_prune_memstats() {}
function boost_dispatch_partitions($run, $partitions) {
    $GLOBALS['state']['activeAtDispatch'] = active_run() === $run;
    if (($GLOBALS['scenario']['dispatch'] ?? 'ok') === 'fail') {
        throw new \RuntimeException('Boost partition 1 of 2 could not be queued.');
    }
    $GLOBALS['state']['dispatched'] = range(1, $partitions);
    $GLOBALS['state']['effects'][] = 'dispatch';
}
function boost_launch_children($run) {
    $GLOBALS['state']['effects'][] = 'launch';
    $GLOBALS['state']['archiveWrites']++;
    // The consumer of an earlier, timed-out run started its child after this run began.
    foreach ($GLOBALS['state']['staleRuns'] ?? array() as $old) {
        $GLOBALS['state']['rows'][$old][1] = 99;
    }
    foreach (array(1, 2) as $partition) { $GLOBALS['state']['rows'][$run][$partition] = 5; }
    return array(1, 2);
}
function boost_wait_children(...$args) { return true; }
function boost_processes_running() { return 0; }
function boost_log_statistics(...$args) { $GLOBALS['state']['effects'][] = 'statistics'; }
function boost_get_arch_table_names() { return array('poller_output_boost_arch_test'); }
function boost_archive_is_empty($name) { return true; }
function dsstats_boost_bottom() {}
function rrdcheck_boost_bottom() {}
function api_plugin_hook(...$args) {}
function boost_purge_cached_png_files(...$args) {}
function db_fetch_row(...$args) { return array('pending' => 1); }
function db_fetch_assoc(...$args) { return array(array('name' => 'poller_output_boost_arch_test')); }
function db_execute($sql) {
    if (strpos($sql, 'TRUNCATE') !== false) { $GLOBALS['state']['rows'] = array(); }
    if (strpos($sql, 'DROP TABLE') !== false) { $GLOBALS['state']['effects'][] = 'drop'; }
    return true;
}
function db_fetch_cell_prepared($sql, $params = array()) {
    $rows = $GLOBALS['state']['rows'][$params[0]] ?? array();
    if (strpos($sql, 'status < 0') !== false) { return count(array_filter($rows, fn($s) => $s < 0)); }
    if (strpos($sql, 'SUM(status)') !== false) { return $rows ? array_sum($rows) : null; }
    return count($rows);
}
// Registered from a shutdown function so it runs after the master's own cleanup.
register_shutdown_function(function () { register_shutdown_function(function () { file_put_contents($GLOBALS['stateFile'], json_encode($GLOBALS['state'])); }); });
FIXTURE;
    $file = tempnam(sys_get_temp_dir(), 'boost-queue-master-');
    try {
        file_put_contents($file, $bootstrap . "\n" . test_php_function_source($source, 'boost_interval_seconds') . "\n" . test_php_function_source($source, 'sig_handler') . "\n" . test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'), 'boost_debug_log_path') . "\n" . $master);
        $process = proc_open(array(PHP_BINARY, $file), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, array_merge(getenv(), array('BOOST_STATE' => $stateFile, 'BOOST_SCENARIO' => json_encode($scenario))));
        stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return array('state' => json_decode(file_get_contents($stateFile), true), 'status' => $status, 'error' => $error);
    } finally {
        unlink($file);
    }
}

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

beforeEach(function () {
    $this->stateFile = tempnam(sys_get_temp_dir(), 'boost-queue-state-');
    unlink($this->stateFile);
});
afterEach(function () {
    is_file($this->stateFile) && unlink($this->stateFile);
});

test('queue mode dispatches one message per partition and succeeds when all report', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'ok'));
    $state = $run['state'];

    expect($run['error'])->toBe('')->and($run['status'])->toBe(0)
        ->and($state['dispatched'])->toBe(array(1, 2))
        ->and($state['activeAtDispatch'])->toBeTrue()
        ->and($state['options']['boost_active_run'])->toBe('')
        ->and($state['options']['boost_poller_status'])->toStartWith('complete')
        ->and($state['effects'])->not->toContain('launch')
        ->and($state['effects'])->toContain('drop', 'statistics');
});

test('queue mode with no consumer times out, fails the run and keeps archives', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'none'));
    $state = $run['state'];

    expect($run['error'])->toBe('')->and($run['status'])->toBe(1)
        ->and($state['options']['boost_poller_status'])->toStartWith('failed')
        ->and($state['options']['boost_last_run_time'])->toBe(1234)
        ->and($state['options']['boost_active_run'])->toBe('')
        ->and($state['effects'])->not->toContain('drop', 'statistics', 'launch')
        ->and(implode("\n", $state['log']))->toContain('no Boost queue worker reported; run "bin/console messenger:consume boost"');
    // The wait ends at the children-mode deadline (3 * 1 s runtime), polling once a second.
    expect(array_slice($state['sleeps'], 1))->toBe(array(1, 1, 1));
});

test('dispatch failure falls back to failed run without launching children', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'dispatch' => 'fail'));
    $state = $run['state'];

    expect($run['error'])->toBe('')->and($run['status'])->toBe(1)
        ->and($state['options']['boost_poller_status'])->toStartWith('failed')
        ->and($state['options']['boost_last_run_time'])->toBe(1234)
        ->and($state['options']['boost_active_run'])->toBe('')
        ->and($state['effects'])->not->toContain('launch', 'drop')
        ->and($state['sleeps'])->toBe(array(7))
        ->and(implode("\n", $state['log']))->toContain('could not queue its partitions (RuntimeException)');
});

test('mode switch with a pending archive processes it once', function () {
    $first = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'none'));
    expect($first['status'])->toBe(1)->and($first['state']['archiveWrites'])->toBe(0);

    // A worker that passed its stale check just before the timeout reports under the old run.
    $state = $first['state'];
    $state['staleRuns'] = array(str_repeat('0', 32));
    $state['rows'][str_repeat('0', 32)] = array(1 => 99);
    file_put_contents($this->stateFile, json_encode($state));

    $second = boost_queue_master_run($this->stateFile, array('mode' => 'children'));
    $final = $second['state'];

    expect($second['status'])->toBe(0)
        ->and($final['archiveWrites'])->toBe(1)
        ->and($final['options']['boost_poller_status'])->toStartWith('complete')
        ->and($final['effects'])->toContain('launch', 'statistics')
        ->and($final['effects'])->not->toContain('dispatch');
    $current = array_values(array_filter(array_keys($final['rows']), fn($run) => $run !== str_repeat('0', 32)));
    expect($current)->toHaveCount(1)
        ->and(array_sum($final['rows'][$current[0]]))->toBe(10)
        ->and($final['rows'][str_repeat('0', 32)])->toBe(array(1 => 99));
});

test('remote collector ignores queue mode', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'poller_id' => 2, 'consumer' => 'ok'));
    $state = $run['state'];

    expect($run['status'])->toBe(0)
        ->and($state['effects'])->toContain('launch')
        ->and($state['effects'])->not->toContain('dispatch')
        ->and($state['options'])->not->toHaveKey('boost_active_run');
});

test('queue run reports a partition that arrives in the last second', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'last-second'));

    expect($run['status'])->toBe(0)
        ->and($run['state']['options']['boost_poller_status'])->toStartWith('complete')
        ->and($run['state']['options']['boost_active_run'])->toBe('');
});

test('partial timeout names the reported count and the consumer', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'one'));

    expect($run['status'])->toBe(1)
        ->and(implode("\n", $run['state']['log']))->toContain('ERROR: Boost queue run timed out: 1 of 2 partitions reported; check "bin/console messenger:consume boost" worker capacity and logs.');
});

test('SIGTERM during the wait clears the active run', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'none', 'at' => 9, 'signal' => true));

    expect($run['error'])->toBe('')
        ->and($run['state']['options']['boost_active_run'])->toBe('')
        ->and($run['state']['options']['boost_poller_status'])->toStartWith('terminated');
});

test('SIGTERM right after the run id is published still clears it', function () {
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'none', 'signalOnPublish' => true));

    expect($run['error'])->toBe('')
        ->and($run['state']['dispatched'])->toBe(array())
        ->and($run['state']['options']['boost_active_run'])->toBe('')
        ->and($run['state']['options']['boost_poller_status'])->toStartWith('terminated');
});

test('a newer run id set meanwhile is not cleared', function ($signal) {
    $newer = str_repeat('f', 32);
    $run = boost_queue_master_run($this->stateFile, array('mode' => 'queue', 'consumer' => 'none', 'at' => 9, 'signal' => $signal, 'newer' => $newer));

    expect($run['error'])->toBe('')
        ->and($run['state']['options']['boost_active_run'])->toBe($newer);
})->with(array('signal' => true, 'timeout' => false));
