<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Contract/ReferenceWriteTransactionRunner.php';
require_once dirname(__DIR__, 2) . '/src/Platform/Infrastructure/Legacy/NativeReferenceWriteTransactionRunner.php';
require_once dirname(__DIR__, 2) . '/src/Inventory/Infrastructure/Legacy/DeviceCollectorCleanup.php';

use Kadupul\Inventory\Infrastructure\Legacy\DeviceCollectorCleanup;

if (preg_match('/\Akadupul_cleanup_[a-f0-9]{16}\z/D', $argv[1] ?? '') !== 1 || preg_match('/\A[1-9][0-9]*\z/D', $argv[2] ?? '') !== 1) {
    throw new RuntimeException('Invalid owned fixture identity');
}
$db = new PDO(getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('USE `' . $argv[1] . '`');
$db->beginTransaction();
$db->query('SELECT poller_id FROM cleanup_host WHERE id=7 FOR UPDATE')->fetchColumn();
$db->exec('UPDATE cleanup_host SET poller_id=3 WHERE id=7');
(new DeviceCollectorCleanup(new \Kadupul\Platform\Infrastructure\Legacy\NativeReferenceWriteTransactionRunner()))->retain($db, [7 => 2], 3);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
$observed = false;
for ($attempt = 0; $attempt < 15; $attempt++) {
    $query = $db->prepare("SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id=? AND trx_state='LOCK WAIT'");
    $query->execute([(int) $argv[2]]);
    if ((int) $query->fetchColumn() === 1) {
        $observed = true;
        break;
    }
    // MariaDB resets the 100ms native observer cache idle period on each read.
    usleep(200000);
}
if (!$db->commit()) {
    throw new RuntimeException('Concurrent fixture commit unavailable');
}
echo json_encode(['observed_host_lock_wait' => $observed], JSON_THROW_ON_ERROR);
