<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace RecoveryControl;
require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4) . '/poller_recovery.php');
$block = \test_php_block_source($source, 'if ($run)');
eval('namespace ' . __NAMESPACE__ . '; function run_recovery() {
    $run = true; $poller_id = 2; $record_limit = 150000; $sleep_time = 1;
    $local_db_cnn_id = "local"; $remote_db_cnn_id = "main";
    $records_inserted = 0; $transfer_failed = false;' . $block . '
    return $transfer_failed;
}');
function db_fetch_cell($sql, $column = '', $log = true, $conn = false) {
    $GLOBALS['recovery_review']['reads']++;
    if ($GLOBALS['recovery_review']['mode'] === 'read_failure') return false;
    if ($GLOBALS['recovery_review']['mode'] === 'fetch_failure') return '2026-11-01 00:00:00';
    return null;
}
function db_fetch_assoc_prepared($sql, $params = [], $log = true, $conn = false) { return false; }
function db_execute_prepared($sql, $params = [], $log = true, $conn = false) {
    $mode = $GLOBALS['recovery_review']['mode'];
    if (($mode === 'pending_failure' && str_contains($sql, "VALUES ('recovery_pending'"))
        || ($mode === 'status_failure' && str_contains($sql, 'SET status = 5'))
        || ($mode === 'completion_failure' && str_contains($sql, 'SET status = 2'))) return false;
    $GLOBALS['recovery_review']['writes'][] = $sql;
    return true;
}
function poller_push_reindex_data_to_poller($device_id = 0, $query_id = 0, $force = false, $conn = false) { return $GLOBALS['recovery_review']['mode'] !== 'metadata_failure'; }
function cacti_log($message, ...$args) {}
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function sleep($seconds) {
    if (++$GLOBALS['recovery_review']['sleeps'] >= 3) throw new \RuntimeException('Review bounded an otherwise repeating recovery loop');
}
test('recovery distinguishes failed reads and metadata writes from an empty recovered queue', function ($mode) {
    $GLOBALS['recovery_review'] = ['mode' => $mode, 'reads' => 0, 'sleeps' => 0, 'writes' => []];
    $bounded = false; $failed = false;
    try { $failed = run_recovery(); } catch (\RuntimeException $e) { $bounded = true; }
    $online = count(array_filter($GLOBALS['recovery_review']['writes'], fn($sql) => str_contains($sql, 'SET status = 2'))) > 0;
    expect($bounded)->toBeFalse();
    $cleared = count(array_filter($GLOBALS['recovery_review']['writes'], fn($sql) => str_contains($sql, "DELETE FROM settings WHERE name='recovery_pending'"))) > 0;
    expect($cleared)->toBe($mode === 'empty_success');
    expect($failed)->toBe($mode !== 'empty_success')->and($online)->toBe($mode === 'empty_success');
})->with(['empty_success', 'read_failure', 'fetch_failure', 'metadata_failure', 'pending_failure', 'status_failure', 'completion_failure']);
