<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/../src/Platform/Contract/CdefReferenceReadiness.php';
require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/CdefReferenceTriggers.php';
require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/CdefReferenceContract.php';
require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/CdefReferenceReadinessProcedure.php';
require_once __DIR__ . '/../src/Platform/Infrastructure/Legacy/CdefReferenceReadiness.php';
require_once __DIR__ . '/../src/GraphDefinition/Infrastructure/Legacy/LegacyCdefDeletion.php';

/** Preserve the selected legacy connection; never promote a remote collector. */
function cdef_reference_primary_connection(): PDO
{
    global $config, $database_sessions, $database_hostname, $database_port, $database_default;

    if (!in_array($config['poller_id'] ?? null, [1, '1'], true)) {
        throw new RuntimeException('The CDEF reference contract must be installed and used on the explicitly configured primary collector.');
    }
    $connection = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$connection instanceof PDO) {
        throw new RuntimeException('The selected primary CDEF database connection is unavailable.');
    }

    return $connection;
}

function cdef_reference_install(): void
{
    (new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceContract(cdef_reference_primary_connection(), 1))->install();
}

/** @param list<int|string> $selection */
function cdef_reference_delete(array $selection): void
{
    $database = cdef_reference_primary_connection();
    $readiness = new \Kadupul\Platform\Infrastructure\Legacy\CdefReferenceReadiness($database, 1);
    (new \Kadupul\GraphDefinition\Infrastructure\Legacy\LegacyCdefDeletion($database, 1, $readiness))->delete($selection);
}
