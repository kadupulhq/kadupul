<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('forced repair preserves live worker ownership and queue', function ($mode) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/cli/batchgapfix.php');
    preg_match('/\tif \(\$force\) \{(.*?)\n\tif \(!register_process_start/s', $source, $match);
    expect($match)->not->toBeEmpty();
    $script = tempnam(sys_get_temp_dir(), 'batch-force-');
    $program = <<<'PHP'
<?php
namespace ForcedRepairProbe;
$force = true;
$mode = $argv[1];
$checks = 0;
function cacti_sizeof($value) { return count($value); }
function db_fetch_assoc($sql) { return array(array('pid'=>12345,'tasktype'=>'batchgapfix','taskname'=>'child','taskid'=>1)); }
function db_fetch_assoc_prepared(...$args) { return db_fetch_assoc(''); }
function cacti_process_pid_for_log($pid) { return $pid; }
function cacti_process_still_running($pid) {
    $GLOBALS['checks']++;
    return $GLOBALS['mode'] !== 'dead' && !($GLOBALS['mode'] === 'stopped' && $GLOBALS['checks'] > 1);
}
function cacti_process_kill(...$args) { return $GLOBALS['mode'] !== 'denied'; }
function cacti_process_kill_denied($pid) { return $GLOBALS['mode'] === 'denied'; }
function posix_kill($pid, $signal) {
    return $signal === 0 ? cacti_process_still_running($pid) : cacti_process_kill($pid);
}
function posix_get_last_error() { return $GLOBALS['mode'] === 'denied' ? 1 : 3; }
function unregister_process(...$args) { echo "UNREGISTER\n"; }
function db_execute($sql) { echo "UNREGISTER\n"; }
PHP;
    file_put_contents($script, $program . "\n\tif (\$force) {" . $match[1] . "\necho 'QUEUE RESET';");
    try {
        $process = proc_open(array(PHP_BINARY, $script, $mode), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        $safe = in_array($mode, array('dead','stopped'), true);
        if ($status === 255) {
            throw new RuntimeException($error . $output);
        }
        expect($status)->toBe($safe ? 0 : 1, $output . $error)
            ->and(strpos($output, 'QUEUE RESET') !== false)->toBe($safe)
            ->and(strpos($output, 'UNREGISTER') !== false)->toBe($safe);
    } finally {
        unlink($script);
    }
})->with(array('denied', 'alive', 'stopped', 'dead'));
