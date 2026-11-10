<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Started by LegacyNotificationDrain. Reads {"batch_size": int,
// "budget_seconds": number} on stdin, runs DeliverNotifications from the
// Symfony container in this process and ends with one
// KADUPUL_ALERTING_DELIVERY_RESULT line. The administrator mail adapters call
// read_config_option(), the legacy user lookup and send_mail(), so they need
// the procedural runtime that include/cli_check.php loads and a console
// process does not; admin_email() reaches the same use case the same way
// (include/admin_notifications.php).

use Kadupul\Alerting\Application\AlertingConfiguration;
use Kadupul\Alerting\Application\DeliverNotifications;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('KADUPUL_REDACT_DATABASE_LOGS', true);
require_once __DIR__ . '/worker-io.php';
$finishOutput = \Kadupul\LegacyWorker\pass_through_output();
require __DIR__ . '/../include/cli_check.php';

$result = ['status' => 'failed'];
try {
    $command = \Kadupul\LegacyWorker\read_json_command(STDIN, 256, 2, InvalidArgumentException::class, 'Invalid command');
    $batch = is_array($command) ? ($command['batch_size'] ?? null) : null;
    $budget = is_array($command) ? ($command['budget_seconds'] ?? null) : null;
    if (!is_array($command) || array_keys($command) !== ['batch_size', 'budget_seconds']
        || !is_int($batch) || $batch < 1 || $batch > AlertingConfiguration::MAX_BATCH_SIZE
        || !(is_int($budget) || is_float($budget)) || !is_finite((float) $budget) || $budget < 0 || $budget > AlertingConfiguration::MAX_BUDGET_SECONDS) {
        throw new InvalidArgumentException('Invalid command');
    }
    $kernel = require __DIR__ . '/../config/bootstrap.php';
    $kernel->boot();
    try {
        $deliver = $kernel->getContainer()->get(DeliverNotifications::class);
        $report = $deliver($batch, (float) $budget);
    } finally {
        $kernel->shutdown();
    }
    $result = [
        'status' => 'ok',
        'sent' => $report->sent,
        'retrying' => $report->retrying,
        'failed' => $report->failed,
        'lost' => $report->lost,
        'suppressed' => $report->suppressed,
    ];
} catch (Throwable $error) {
    // The class gives operators a cause; the message could carry a DSN, query or server reply.
    cacti_log('ERROR: Alert notification delivery failed: ' . get_class($error), false, 'ALERTING');
}
$finishOutput('KADUPUL_ALERTING_DELIVERY_RESULT', $result, JSON_THROW_ON_ERROR);
exit($result['status'] === 'ok' ? 0 : 1);
