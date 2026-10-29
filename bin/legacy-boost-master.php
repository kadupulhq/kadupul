<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Started by BoostRunHandler. Reads {"force": bool, "debug": bool} on stdin,
// runs poller_boost.php as the master, streams its output, and ends with one
// BOOST_MASTER line. poller_boost.php dispatches at top level, so it cannot be
// included for its functions; it stays the only code that decides whether a
// run is due, takes the master registration and writes the status row.
//
// The worker enforces the master's window itself: register_process_start()
// only replaces a timed-out master when a new one registers, and once the
// scheduler owns Boost nothing else starts one. Without this deadline a hung
// master would hold the single scheduler consumer forever.
use Symfony\Component\Process\Process;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ob_start();
require __DIR__ . '/../include/cli_check.php';
ob_end_clean();

// SIGTERM from the consumer or the scheduler worker must not leave a master
// writing RRD files that nothing supervises.
$masterChild = null;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stopChild = static function () use (&$masterChild): never {
        if ($masterChild instanceof Process && $masterChild->isRunning()) {
            // stop() sends SIGTERM itself so the master can release its locks; the
            // signal argument is what follows once the 5 s grace has passed.
            $masterChild->stop(5, SIGKILL);
        }
        print 'BOOST_MASTER=' . json_encode(['status' => 'failed']) . PHP_EOL;
        exit(1);
    };
    pcntl_signal(SIGTERM, $stopChild);
    pcntl_signal(SIGINT, $stopChild);
}

$result = ['status' => 'failed'];
try {
    $input = stream_get_contents(STDIN, 1025);
    $command = is_string($input) && strlen($input) <= 1024 ? json_decode($input, true) : null;
    $force = is_array($command) ? ($command['force'] ?? null) : null;
    $debug = is_array($command) ? ($command['debug'] ?? null) : null;
    if (is_bool($force) && is_bool($debug)) {
        $result = legacy_boost_master($force, $debug, $config, $masterChild);
    }
} catch (\Throwable $failure) {
    // The class gives operators a cause; the message could carry data values.
    fwrite(STDERR, 'legacy-boost-master: ' . $failure::class . " while running the master\n");
}
print 'BOOST_MASTER=' . json_encode($result) . PHP_EOL;
exit($result['status'] === 'failed' ? 1 : 0);

/**
 * @param array<string, mixed> $config
 * @return array{status: string}
 */
function legacy_boost_master(bool $force, bool $debug, array $config, ?Process &$masterChild): array
{
    $php = read_config_option('path_php_binary');
    if (!is_string($php) || $php === '' || !is_executable($php)) {
        $php = PHP_BINARY;
    }
    $arguments = [$php, $config['base_path'] . '/poller_boost.php'];
    if ($force) {
        $arguments[] = '--force';
    }
    if ($debug) {
        $arguments[] = '--debug';
    }
    // The master registers runtime * 3 with register_process_start(), but that
    // window opens after preparation and a 7 s sleep, and cleanup, dsstats,
    // rrdcheck and the plugin hook run after it. The 600 s margin the queue keeps
    // above the partition timeout lets the master time out and log the cause first.
    $deadline = microtime(true) + max(1, (int) read_config_option('boost_rrd_update_max_runtime')) * 3 + 600;
    $child = $masterChild = new Process($arguments, $config['base_path'], null, null, null);
    $child->start(static function (string $type, string $buffer): void {
        if ($type === Process::ERR) {
            fwrite(STDERR, $buffer);
        } else {
            print $buffer;
        }
    });
    while ($child->isRunning()) {
        if (microtime(true) >= $deadline) {
            $child->stop(5, SIGKILL);
            legacy_boost_stop_children();
            fwrite(STDERR, "legacy-boost-master: the master exceeded its deadline and was stopped\n");

            return ['status' => 'failed'];
        }
        usleep(100000);
    }
    $child->wait();

    // Rows flushed are not reported: the master keeps no per-run total
    // outside its log line, so there is nothing cheap to read back.
    return ['status' => $child->getExitCode() === 0 ? 'ok' : 'failed'];
}

/**
 * A SIGKILLed master cannot end the boost children it forked, so stop the ones
 * still registered, as boost_kill_running_processes() does for the next master.
 * poller_boost.php dispatches at top level and cannot be included for it.
 */
function legacy_boost_stop_children(): void
{
    $children = db_fetch_assoc_prepared('SELECT pid, taskid FROM processes WHERE tasktype = ? AND taskname = ?', ['boost', 'child']);
    if (!is_array($children)) {
        return;
    }
    foreach ($children as $registered) {
        $pid = (int) $registered['pid'];
        if ($pid > 0 && $pid !== getmypid() && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        }
        unregister_process('boost', 'child', (int) $registered['taskid'], $pid);
    }
}
