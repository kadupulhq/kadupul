<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals and plugin hooks from Symfony. What the libraries print is the original script's own
// output (rrdtool's "OK" lines), so it passes through; the callback only notes whether the marker needs a new line.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
$endsWithNewline = true;
ob_start(static function (string $chunk) use (&$endsWithNewline): string {
    if ($chunk !== '') {
        $endsWithNewline = str_ends_with($chunk, "\n");
    }

    return $chunk;
}, 1);
require __DIR__ . '/../include/cli_check.php';
// The libraries cli/poller_output_empty.php loaded, because process_poller_output() reaches into them.
foreach (['poller', 'data_query', 'dsstats', 'dsdebug', 'boost', 'rrd'] as $library) {
    require_once $config['base_path'] . '/lib/' . $library . '.php';
}

$status = 'failed';
$updates = 0;
$pipe = false;
try {
    $input = stream_get_contents(STDIN, 20001);
    if (strlen($input) > 20000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if ($command !== []) {
        throw new RuntimeException('Invalid command');
    }
    // As the script did: it never left the installation's own database, on a remote collector too.
    $pipe = rrd_init(true, false, true);
    if ($pipe === false) {
        $status = 'rrd_unavailable';
    } else {
        $status = 'ok';
        // The loop of cli/poller_output_empty.php, step for step.
        while (true) {
            $pending = db_fetch_cell('SELECT count(*) FROM poller_output');
            if (!is_numeric($pending)) {
                $status = 'count_unavailable';
                break;
            }
            if ((int) $pending === 0) {
                break;
            }
            $updated = process_poller_output($pipe, false);
            $remaining = db_fetch_cell('SELECT count(*) FROM poller_output');
            if ($updated === false || !is_numeric($remaining) || $remaining >= $pending) {
                $status = 'no_progress';
                break;
            }
            $updates += $updated;
        }
        rrd_close($pipe);
        $pipe = false;
    }
} catch (Throwable $error) {
    $status = 'failed';
    cacti_log('ERROR: Poller output worker failed: ' . get_class($error), false, 'WEBLOG');
    if ($pipe !== false) {
        rrd_close($pipe);
    }
}
ob_end_flush();
if (!$endsWithNewline) {
    echo PHP_EOL;
}
echo 'KADUPUL_POLLER_OUTPUT_EMPTY_RESULT=' . json_encode(['status' => $status, 'updates' => $status === 'ok' ? $updates : 0], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
