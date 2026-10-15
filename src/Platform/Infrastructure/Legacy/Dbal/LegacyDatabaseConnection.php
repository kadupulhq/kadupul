<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Connection;

/**
 * DBAL for code that runs inside the legacy bootstrap, on the same session as
 * the db_* helpers, so it shares their transaction, locks, sql_mode, time zone,
 * utf8 charset and credentials. It never opens a connection: when the legacy
 * code has none, there is none.
 *
 * Symfony-native code keeps using the ConnectionTarget connections, which are
 * separate sessions with their own credentials (the web target prefers the
 * read-only user). Handing them this connection instead would let web code
 * borrow the legacy writer.
 */
final readonly class LegacyDatabaseConnection
{
    /**
     * The PDO a db_* helper would use for the same argument. It is resolved
     * on every call, as the helpers do, so db_switch_remote_to_main() and
     * db_switch_main_to_local() route DBAL work the same way.
     */
    public static function select(\PDO|false|null $db_conn = false): ?\PDO
    {
        if ($db_conn instanceof \PDO) {
            return $db_conn;
        }
        global $database_sessions, $database_hostname, $database_port, $database_default;
        $session = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;

        return $session instanceof \PDO ? $session : null;
    }

    /**
     * A fresh DBAL wrapper each time, holding no transaction state of its
     * own: DBAL's nesting counter would otherwise disagree with transactions
     * the legacy code opens and closes directly on the PDO.
     */
    public static function open(\PDO $pdo): Connection
    {
        return new Connection([], new SharedPdoDriver($pdo));
    }
}
