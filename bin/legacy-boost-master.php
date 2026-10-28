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
    $stopChild = static function (): never {
        global $masterChild;

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
        $result = legacy_boost_master($force, $debug);
    }
} catch (\Throwable $failure) {
    // The class gives operators a cause; the message could carry data values.
    fwrite(STDERR, 'legacy-boost-master: ' . $failure::class . " while running the master\n");
}
print 'BOOST_MASTER=' . json_encode($result) . PHP_EOL;
exit($result['status'] === 'failed' ? 1 : 0);

/** @return array{status: string} */
function legacy_boost_master(bool $force, bool $debug): array
{
    global $config, $masterChild;

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
    // The window the master registers with register_process_start().
    $deadline = microtime(true) + max(1, (int) read_config_option('boost_rrd_update_max_runtime')) * 3;
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
