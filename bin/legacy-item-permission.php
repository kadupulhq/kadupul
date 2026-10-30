<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Kadupul\IdentityAccess\Domain\ItemType;
use Kadupul\IdentityAccess\Infrastructure\Legacy\PermissionMutation;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolate procedural globals, plugin hooks and session messages from Symfony.
define('KADUPUL_REDACT_DATABASE_LOGS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';

$status = 'failed';
$granted = false;
try {
    $input = stream_get_contents(STDIN, 1001);
    if (strlen($input) > 1000) {
        throw new RuntimeException('Payload too large');
    }
    $command = json_decode($input, true, 4, JSON_THROW_ON_ERROR);
    if (!is_array($command) || array_diff(array_keys($command), ['database', 'user', 'type', 'item']) !== []
        || !in_array($command['database'] ?? null, ['local', 'main'], true)
        || !is_int($command['user'] ?? null) || $command['user'] <= 0
        || !is_int($command['type'] ?? null) || ItemType::tryFrom($command['type']) === null
        || !is_int($command['item'] ?? null) || $command['item'] <= 0) {
        throw new RuntimeException('Invalid command');
    }
    // The cli/ scripts worked on the main database from a remote collector; --local keeps the local one.
    if ($command['database'] === 'main' && (int) ($config['poller_id'] ?? 1) > 1) {
        db_switch_remote_to_main();
    }
    // The statement the web's user form sends: it locks the user, raises the
    // permission epoch and ends remembered logins in one transaction.
    $granted = PermissionMutation::write(
        'REPLACE INTO user_auth_perms (user_id, item_id, type) VALUES (?, ?, ?)',
        [$command['user'], $command['item'], $command['type']],
        false,
        $command['user'],
    );
    $status = 'ok';
} catch (Throwable $error) {
    cacti_log('ERROR: Item permission worker failed: ' . get_class($error), false, 'AUTH');
}
ob_end_clean();
echo 'KADUPUL_ITEM_PERMISSION_RESULT=' . json_encode(['status' => $status, 'granted' => $granted], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($status === 'ok' ? 0 : 1);
