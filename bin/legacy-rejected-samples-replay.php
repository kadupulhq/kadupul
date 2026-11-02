<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals and plugin hooks from Symfony. What the libraries print is the original script's own
// output, so it passes through; the callback only notes whether the marker needs a new line.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
$endsWithNewline = true;
ob_start(static function (string $chunk) use (&$endsWithNewline): string {
    if ($chunk !== '') {
        $endsWithNewline = str_ends_with($chunk, "\n");
    }

    return $chunk;
}, 1);
require __DIR__ . '/../include/cli_check.php';
// The libraries cli/replay_rejected_samples.php loaded.
require_once $config['base_path'] . '/lib/poller.php';
require_once $config['base_path'] . '/lib/rrd_maintenance.php';

$status = 'failed';
$samples = 0;
$reason = '';
try {
    $input = stream_get_contents(STDIN, 20001);
    if (strlen($input) > 20000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['local_data_id', 'dry_run']) !== []
        || !array_key_exists('local_data_id', $command) || !($command['local_data_id'] === null || (is_int($command['local_data_id']) && $command['local_data_id'] > 0))
        || !is_bool($command['dry_run'] ?? null)) {
        throw new RuntimeException('Invalid command');
    }
    $localDataId = $command['local_data_id'];
    $dryRun = $command['dry_run'];
    // The script's checks, in its order. An online remote collector queues into the main database, where the drain also dead-letters.
    if (($config['poller_id'] ?? 1) > 1 && ($config['connection'] ?? '') === 'online') {
        $status = 'remote_collector';
    } else {
        // Replay writes to poller_output on this connection; a MEMORY queue would lose them on restart.
        $queueError = $dryRun ? '' : rrd_maintenance_queue_configuration_error(false);
        if ($queueError !== '') {
            $status = 'unsafe_queue';
            $reason = $queueError;
        } else {
            $replayed = poller_replay_rejected($localDataId, $dryRun);
            if ($replayed !== false) {
                $samples = (int) $replayed;
                $status = 'ok';
                if (!$dryRun) {
                    cacti_log('NOTE: Replayed ' . $samples . ' rejected samples for ' . ($localDataId === null ? 'all data sources' : 'Local Data ID ' . $localDataId) . ' into poller_output.', false, 'POLLER');
                }
            }
        }
    }
} catch (Throwable $error) {
    $status = 'failed';
    cacti_log('ERROR: Rejected sample replay worker failed: ' . get_class($error), false, 'WEBLOG');
}
ob_end_flush();
if (!$endsWithNewline) {
    echo PHP_EOL;
}
echo 'KADUPUL_REJECTED_SAMPLES_REPLAY_RESULT=' . json_encode(['status' => $status, 'samples' => $status === 'ok' ? $samples : 0, 'reason' => $reason], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
