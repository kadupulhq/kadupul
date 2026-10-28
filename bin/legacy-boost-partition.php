<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Started by LegacyBoostPartitionWriter. Reads {"run": "<hex>", "partition": N}
// on stdin and ends its output with one BOOST_PARTITION line. The poller_boost.php
// child stays the only writer of the status row; this worker starts it for the
// run the master is still waiting on, then reads the row back.
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ob_start();
require __DIR__ . '/../include/cli_check.php';

// The consumer's outer timeout stops this worker with SIGTERM. The child must
// not outlive it: it would keep writing RRD files after the master gave up.
$partitionChild = null;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stopChild = static function () use (&$partitionChild): never {
        if ($partitionChild instanceof Process && $partitionChild->isRunning()) {
            // stop() sends SIGTERM itself so the child can release its locks; the
            // signal argument is what follows once the 5 s grace has passed.
            $partitionChild->stop(5, SIGKILL);
        }
        ob_end_clean();
        print 'BOOST_PARTITION=' . json_encode(['status' => 'failed']) . PHP_EOL;
        exit(1);
    };
    pcntl_signal(SIGTERM, $stopChild);
    pcntl_signal(SIGINT, $stopChild);
}

$result = ['status' => 'failed'];
try {
    $input = stream_get_contents(STDIN, 1025);
    $command = is_string($input) && strlen($input) <= 1024 ? json_decode($input, true) : null;
    $run = is_array($command) ? ($command['run'] ?? null) : null;
    $partition = is_array($command) ? ($command['partition'] ?? null) : null;
    if (is_string($run) && preg_match('/^[0-9a-f]{32}$/D', $run) === 1 && is_int($partition) && $partition >= 1) {
        $result = legacy_boost_partition($run, $partition, $config, $partitionChild);
    }
} catch (\Throwable $failure) {
    // The class gives operators a cause; the message could carry data values.
    fwrite(STDERR, 'legacy-boost-partition: ' . $failure::class . " while flushing a partition\n");
}
ob_end_clean();
print 'BOOST_PARTITION=' . json_encode($result) . PHP_EOL;
exit($result['status'] === 'failed' ? 1 : 0);

/**
 * @param array<string, mixed> $config
 * @return array{status: string, rows?: int}
 */
function legacy_boost_partition(string $run, int $partition, array $config, ?Process &$partitionChild): array
{
    // The master clears this setting when it stops waiting; a late message
    // must not flush data the master already counted as failed.
    if (read_config_option('boost_active_run', true) !== $run) {
        return ['status' => 'stale'];
    }
    $php = read_config_option('path_php_binary');
    if (!is_string($php) || $php === '' || !is_executable($php)) {
        $php = PHP_BINARY;
    }
    // Three times the update window, as the master allows each child.
    $deadline = max(1, (int) read_config_option('boost_rrd_update_max_runtime')) * 3;
    $child = $partitionChild = new Process([$php, $config['base_path'] . '/poller_boost.php', '--child=' . $partition, '--run=' . $run], $config['base_path'], null, null, $deadline);
    try {
        $child->run(static function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                fwrite(STDERR, $buffer);
            }
        });
    } catch (ProcessTimedOutException) {
        return ['status' => 'failed'];
    }
    if ($child->getExitCode() !== 0) {
        return ['status' => 'failed'];
    }
    $rows = db_fetch_cell_prepared('SELECT status FROM poller_output_boost_processes WHERE run_id = ? AND process_handler = ?', [$run, $partition]);
    if ((!is_string($rows) && !is_int($rows)) || preg_match('/^\d{1,9}$/D', (string) $rows) !== 1) {
        return ['status' => 'failed'];
    }

    return ['status' => 'ok', 'rows' => (int) $rows];
}
