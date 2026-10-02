<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/LegacyReferenceWriteTransaction.php';

/**
 * Run local writes on the selected legacy connection, including collectors.
 * This does not install reference constraints or roll back another server.
 * The operation must confirm every read/write and return false on failure.
 *
 * @param list<string> $participants
 */
function reference_write_atomic(Closure $operation, array $participants): mixed
{
    global $database_sessions, $database_hostname, $database_port, $database_default;

    $database = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$database instanceof PDO) {
        return false;
    }
    try {
        return (new \Kadupul\Platform\Infrastructure\Legacy\LegacyReferenceWriteTransaction($database))->run($operation, $participants);
    } catch (Throwable) {
        return false;
    }
}
