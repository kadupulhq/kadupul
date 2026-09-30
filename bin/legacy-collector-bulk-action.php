<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\CollectorBulkAccessDenied;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorFullSynchronizer;
use Kadupul\CollectorAdministration\Infrastructure\Persistence\PdoCollectorOperatorAuthorization;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/poller.php';
require_once __DIR__ . '/../src/CollectorAdministration/Domain/CollectorBulkAction.php';
require_once __DIR__ . '/../src/CollectorAdministration/Domain/CollectorSelection.php';
require_once __DIR__ . '/../src/CollectorAdministration/Infrastructure/Persistence/CollectorBulkAccessDenied.php';
require_once __DIR__ . '/../src/CollectorAdministration/Infrastructure/Persistence/PdoCollectorOperatorAuthorization.php';
require_once __DIR__ . '/../src/CollectorAdministration/Infrastructure/Persistence/PdoCollectorFullSynchronizer.php';

ini_set('memory_limit', '-1');
set_time_limit(900);

$successful = [];
$failed = [];
$validCommand = false;
try {
    $input = stream_get_contents(STDIN, 32001);
    if (strlen($input) > 32000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['actor', 'action', 'ids']) !== []
        || !is_int($command['actor'] ?? null) || $command['actor'] <= 0
        || !is_string($command['action'] ?? null) || !is_array($command['ids'] ?? null)) {
        throw new RuntimeException('Invalid command');
    }
    $action = CollectorBulkAction::from($command['action']);
    $selection = new CollectorSelection($command['ids']);
    $ids = $selection->ids;
    if ($action->protectsPrimary() && in_array(1, $ids, true)) {
        throw new RuntimeException('Primary collector protection failed');
    }
    if ($action !== CollectorBulkAction::FullSync || (int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException('The isolated worker only accepts full synchronization on the primary collector');
    }
    $validCommand = true;
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"];
    $_SESSION['sess_user_id'] = $command['actor'];
    if (defined('KADUPUL_THROW_DATABASE_ERRORS') && !KADUPUL_THROW_DATABASE_ERRORS) {
        throw new RuntimeException('Database errors must fail synchronization.');
    }
    if (!defined('KADUPUL_THROW_DATABASE_ERRORS')) {
        define('KADUPUL_THROW_DATABASE_ERRORS', true);
    }
    $result = (new PdoCollectorFullSynchronizer(new PdoCollectorOperatorAuthorization()))->run(
        $connection,
        $command['actor'],
        $selection,
        static fn(int $collectorId): bool => replicate_out($collectorId)
    );
    $successful = $result['successful'];
    $failed = $result['failed'];
    $status = $failed === [] ? 'NOTE' : 'WARNING';
    cacti_log($status . ': Collector synchronization requested by user ' . $command['actor'] . ' for [' . implode(', ', $ids) . '], Successful/Failed[' . count($successful) . '/' . count($failed) . '].', false, 'WEBUI');
} catch (Throwable) {
    if ($failed === []) {
        $failed = isset($ids) ? $ids : [];
    }
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_COLLECTOR_ACTION_RESULT=' . json_encode([
    'actor' => $command['actor'] ?? null,
    'action' => $command['action'] ?? null,
    'successful' => $successful,
    'failed' => $failed,
]) . "\n";
exit($validCommand ? 0 : 1);
