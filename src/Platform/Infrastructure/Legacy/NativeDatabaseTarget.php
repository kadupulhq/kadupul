<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseTargetSelection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/** The only global-state adapter in the database target identity check. */
#[AsAlias(DatabaseTargetSelection::class)]
final class NativeDatabaseTarget implements DatabaseTargetSelection
{
    public function select(string $expected): ?\PDO
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expected) !== 1) {
            return null;
        }
        $configuration = $GLOBALS['config'] ?? null;
        if (!is_array($configuration)) {
            return null;
        }
        $sessions = $GLOBALS['database_sessions'] ?? null;
        $host = $GLOBALS['database_hostname'] ?? null;
        $port = $GLOBALS['database_port'] ?? null;
        $schema = $GLOBALS['database_default'] ?? null;
        if (!is_array($sessions) || !is_string($host) || (!is_int($port) && !is_string($port)) || !is_string($schema)) {
            return null;
        }
        // Resolve the same active native pool entry as the db_* helpers. Keep
        // this legacy-state capture here, outside the application boundary.
        $database = $sessions[$host . ':' . $port . ':' . $schema] ?? null;
        if (!$database instanceof \PDO) {
            return null;
        }
        $actual = DatabaseFingerprint::capture($database, [
            'collector_id' => $configuration['poller_id'] ?? null,
            'connection_status' => $configuration['connection'] ?? 'online',
            'host' => $host,
            'port' => $port,
        ]);
        return hash_equals($expected, $actual) ? $database : null;
    }
}
