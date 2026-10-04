<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

use Kadupul\Inventory\Domain\DeviceBulkAssignment;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceBulkAssignmentWriter;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorTransfer;

final class TransferFailureDatabase extends PDO
{
    public array $queries = [];

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
        $this->exec('CREATE TABLE poller_command (poller_id INTEGER, action INTEGER, command TEXT)');
        $this->sqliteCreateFunction('SUBSTRING_INDEX', static fn(string $value, string $delimiter, int $count): string => explode($delimiter, $value)[$count - 1], 3);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->queries[] = $query;
        return parent::prepare($query, $options);
    }
}

$calls = [];
function api_device_replicate_out(int $device, int $target): bool
{
    global $calls;
    $calls[] = [$device, $target];
    return false;
}

define('POLLER_COMMAND_PURGE', 4);
$primary = new TransferFailureDatabase();
$remote = new TransferFailureDatabase();
$primary->beginTransaction();
$error = null;
try {
    if (($argv[1] ?? '') === 'bulk') {
        (new DeviceBulkAssignmentWriter(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->apply($primary, [3 => $remote], new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 1, 0), new DeviceBulkAssignment('collector', 3));
    } else {
        (new DeviceCollectorTransfer(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->apply($primary, [3 => $remote], 7, 1, 3);
    }
} catch (RuntimeException $failure) {
    $error = $failure->getMessage();
}
echo json_encode(['error' => $error, 'calls' => $calls, 'primary_queries' => $primary->queries, 'remote_queries' => $remote->queries, 'transaction_active' => $primary->inTransaction()], JSON_THROW_ON_ERROR);
