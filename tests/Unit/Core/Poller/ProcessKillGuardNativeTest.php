<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace ProcessKillGuardNativeTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/*
 * A process-table pid is stored data. posix_kill() narrows it to a 32 bit
 * pid_t, so 4294967295 reaches kill(2) as -1, and 0 addresses the caller's
 * own process group. Every child below disables the native posix_kill() and
 * records what production code would have sent, so a regression is reported
 * instead of signalling the test runner.
 */

const LIVE_PID = 2147483000;

/** Values a corrupted or tampered row can hold that must never be signalled. */
function reserved_pids(): array
{
    return array('4294967295', '2147483648', '0', '1', '-1', '12x', '');
}

/**
 * Run production code in a child PHP with recording doubles at the system and
 * database boundaries.
 *
 * @param array<int, array{0: string, 1: string}> $extract file and function pairs to load from scripts
 *
 * @return array{signals: array<int, array{0: string, 1: int}>, log: array<int, string>, writes: array<int, string>, result: mixed}
 */
function run(string $body, array $rows, array $extract = array(), ?array $row = null): array
{
    $root = dirname(__DIR__, 4);
    $functions = '';
    foreach ($extract as [$file, $name]) {
        $functions .= \test_php_function_source(file_get_contents($root . '/' . $file), $name) . "\n";
    }

    $script = tempnam(sys_get_temp_dir(), 'pid-guard-');
    $program = '<?php
$GLOBALS["signals"] = array();
$GLOBALS["log"] = array();
$GLOBALS["writes"] = array();
$GLOBALS["result"] = null;
$GLOBALS["errno"] = 0;
$GLOBALS["rows"] = ' . var_export($rows, true) . ';
$GLOBALS["row"] = ' . var_export($row, true) . ';
foreach (array("POLLER_VERBOSITY_NONE" => 1, "POLLER_VERBOSITY_LOW" => 2, "POLLER_VERBOSITY_MEDIUM" => 3, "POLLER_VERBOSITY_HIGH" => 4, "POLLER_VERBOSITY_DEBUG" => 5, "SQL_NO_CACHE" => "") as $name => $value) {
    define($name, $value);
}
register_shutdown_function(function () {
    echo "\n@@RESULT@@", json_encode(array("signals" => $GLOBALS["signals"], "log" => $GLOBALS["log"], "writes" => $GLOBALS["writes"], "result" => $GLOBALS["result"]));
});
function posix_kill($pid, $signal) {
    $GLOBALS["signals"][] = array((string) $pid, $signal);
    $GLOBALS["errno"] = (string) $pid === "' . LIVE_PID . '" ? 0 : 3;
    return $GLOBALS["errno"] === 0;
}
function posix_get_last_error() { return $GLOBALS["errno"]; }
function cacti_log($message, $output = false, $environ = "CMDPHP", $level = 0) { $GLOBALS["log"][] = $message; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function array_rekey($array, $key, $value) { $out = array(); foreach ($array as $item) { $out[$item[$key]] = $item[$value]; } return $out; }
function db_table_exists($table) { return true; }
function db_fetch_assoc($sql, $log = true, $cnn = false) { return $GLOBALS["rows"]; }
function db_fetch_assoc_prepared($sql, $params = array()) { return $GLOBALS["rows"]; }
function db_fetch_row_prepared($sql, $params = array()) { return $GLOBALS["row"]; }
function db_execute($sql, $log = true, $cnn = false) { $GLOBALS["writes"][] = $sql; return true; }
function db_execute_prepared($sql, $params = array()) { $GLOBALS["writes"][] = trim(preg_replace("/\s+/", " ", $sql)) . " " . json_encode($params); return true; }
function clearTask($network_id, $pid) {}
function automation_debug($message) {}
require ' . var_export($root . '/lib/poller.php', true) . ';
' . $functions . '
' . $body . '
';
    file_put_contents($script, $program);

    try {
        $process = proc_open(
            array(PHP_BINARY, '-d', 'disable_functions=posix_kill,posix_get_last_error', '-d', 'display_errors=stderr', $script),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        expect($process)->not->toBeFalse();
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
    } finally {
        unlink($script);
    }

    expect($status)->toBe(0, $error . $output);
    expect($error)->toBe('');

    $marker = strrpos((string) $output, "\n@@RESULT@@");
    $decoded = $marker === false ? null : json_decode(substr($output, $marker + 11), true);
    expect($decoded)->toBeArray($error . $output);

    return $decoded;
}

/** Rows for every reserved value plus one live registered child. */
function table_rows(string $tasktype): array
{
    $rows = array();
    foreach (array_merge(reserved_pids(), array((string) LIVE_PID)) as $index => $pid) {
        $rows[] = array('pid' => $pid, 'tasktype' => $tasktype, 'taskname' => 'child', 'taskid' => $index + 1, 'timeout' => 300);
    }

    return $rows;
}

/** Signals sent to anything other than the live child. */
function stray_signals(array $result): array
{
    return array_values(array_filter($result['signals'], fn(array $signal): bool => $signal[0] !== (string) LIVE_PID));
}

test('pid validation accepts only positive values pid_t can hold', function () {
    $result = run(
        '$GLOBALS["result"] = array_map(fn($pid) => cacti_process_pid_is_valid($pid), array(1, "2", 2147483647, "2147483647", "0002", 0, -1, "4294967295", "2147483648", PHP_INT_MAX, "1.5", "1e3", " 7", "7\n", "", null, true, array(5)));',
        array()
    );

    expect($result['result'])->toBe(array(true, true, true, true, true, false, false, false, false, false, false, false, false, false, false, false, false, false));
});

test('init and unrepresentable pids are system pids that a refused kill never signals', function () {
    $result = run(
        '$GLOBALS["result"] = array("system" => array_map("is_system_pid", array(1, "1", 0, "4294967295", 2, 42, 12345)), "kill" => array_map(fn($pid) => cacti_process_kill($pid, SIGTERM), array(1, 0, "4294967295", "12\nforged")));',
        array()
    );

    expect($result['result']['system'])->toBe(array(true, true, true, true, false, false, false))
        ->and($result['result']['kill'])->toBe(array(false, false, false, false))
        ->and($result['signals'])->toBe(array())
        ->and(implode("\n", $result['log']))->toContain('PID 12?forged')
        ->and(implode('', $result['log']))->not->toContain("\n");
});

test('the guarded kill signals a valid registered pid', function () {
    $result = run('$GLOBALS["result"] = cacti_process_kill(' . LIVE_PID . ', SIGTERM);', array());

    expect($result['result'])->toBeTrue()
        ->and($result['signals'])->toBe(array(array((string) LIVE_PID, SIGTERM)));
});

test('timed-out registry cleanup signals only the live child', function () {
    $result = run('timeout_kill_registered_processes();', table_rows('poller'));

    expect(stray_signals($result))->toBe(array())
        ->and($result['signals'])->toContain(array((string) LIVE_PID, SIGTERM))
        ->and(count(array_filter($result['writes'], fn(string $sql): bool => str_starts_with($sql, 'DELETE FROM processes'))))->toBe(count(table_rows('poller')));
});

test('a timed-out registration whose pid narrows to -1 is retired without a signal', function ($pid) {
    $result = run(
        '$GLOBALS["result"] = register_process_start("poller", "master", 0, 300);',
        array(),
        array(),
        array('pid' => $pid, 'tasktype' => 'poller', 'taskname' => 'master', 'taskid' => 0, 'timeout' => 300, 'timeout_exceeded' => 1700000000, 'current_timestamp' => 1700000400)
    );

    expect($result['result'])->toBeTrue()
        ->and($result['signals'])->toBe(array())
        ->and(implode("\n", $result['writes']))->toContain('INSERT INTO processes');
})->with(array('4294967295', '1'));

test('worker cleanup signals only the live child', function (string $file, string $function, string $tasktype) {
    $result = run($function . '();', table_rows($tasktype), array(array($file, $function)));

    expect(stray_signals($result))->toBe(array())
        ->and($result['signals'])->toContain(array((string) LIVE_PID, SIGTERM));
})->with(array(
    'float' => array('cli/float_rrdfiles.php', 'float_kill_running_processes', 'rfloat'),
    'pushout' => array('bin/legacy-poller-cache-rebuild.php', 'pushout_kill_running_processes', 'pushout'),
    'dsstats' => array('lib/dsstats.php', 'dsstats_kill_running_processes', 'dsstats'),
    'rrdcheck' => array('lib/rrdcheck.php', 'rrdcheck_kill_running_processes', 'rrdcheck'),
    'boost' => array('poller_boost.php', 'boost_kill_running_processes', 'boost'),
    'commands' => array('poller_commands.php', 'commands_kill_running_processes', 'commands'),
));

test('the master poller signal handler signals only valid poller_time pids', function () {
    $result = run('sig_handler(SIGTERM);', table_rows('poller'), array(array('poller.php', 'sig_handler')));

    expect(stray_signals($result))->toBe(array())
        ->and($result['signals'])->toContain(array((string) LIVE_PID, SIGTERM));
});

test('automation cancel and its process helpers signal only valid pids', function () {
    $result = run(
        '$GLOBALS["network_id"] = 7; $GLOBALS["thread"] = 0; $GLOBALS["master"] = true; $GLOBALS["poller_id"] = 1;
        $GLOBALS["helpers"] = array_map(fn($pid) => array(isProcessRunning($pid), killProcess($pid)), array("4294967295", "1", "0"));
        $GLOBALS["result"] = $GLOBALS["helpers"];
        sig_handler(SIGTERM);',
        table_rows('automation'),
        array(array('poller_automation.php', 'isProcessRunning'), array('poller_automation.php', 'killProcess'), array('poller_automation.php', 'sig_handler'))
    );

    expect($result['result'])->toBe(array(array(false, false), array(false, false), array(false, false)))
        ->and(stray_signals($result))->toBe(array())
        ->and($result['signals'])->toContain(array((string) LIVE_PID, SIGTERM));
});

test('a forced batch repair ignores reserved rows instead of probing them', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/batchgapfix.php');
    preg_match('/\tif \(\$force\) \{(.*?)\n\tif \(!register_process_start/s', $source, $match);
    expect($match)->not->toBeEmpty();
    $rows = array();
    foreach (reserved_pids() as $index => $pid) {
        $rows[] = array('pid' => $pid, 'tasktype' => 'batchgapfix', 'taskname' => 'child', 'taskid' => $index + 1);
    }

    $result = run('$force = true; if ($force) {' . $match[1] . ' $GLOBALS["result"] = "reset";', $rows);

    expect($result['signals'])->toBe(array())
        ->and($result['result'])->toBe('reset');
});

test('recovery treats an unrepresentable recorded pid as stale', function ($pid) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller_recovery.php');
    $start = strpos($source, 'if (!empty($recovery_pid)) {');
    $end = strpos($source, 'if ($run) {', $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();

    $result = run('$local_db_cnn_id = false; $recovery_pid = ' . var_export($pid, true) . '; ' . substr($source, $start, $end - $start) . ' $GLOBALS["result"] = $run;', array());

    expect($result['result'])->toBeTrue()
        ->and($result['signals'])->toBe(array());
})->with(array('4294967295', '-1'));
