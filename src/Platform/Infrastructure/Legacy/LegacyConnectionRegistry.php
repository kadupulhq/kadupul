<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * The connection state lib/database.php works from: the open sessions keyed
 * "hostname:port:database", the settings each was opened with, the default
 * key, and a collector's local and main handles.
 *
 * The state still lives in the globals of those names. include/global.php
 * assigns them, the bin/legacy-*.php handoffs, lib/poller.php and plugins read
 * them, and test fixtures and handoffs assign the session array and the key
 * directly after the library loads. Every method therefore reads the globals
 * when it is called, never at construction, so a write from any of those
 * places is seen at once, as it was when each helper declared them. Taking a
 * reference to a missing global creates it as null, as `global` does, so the
 * helpers' warnings and the names left behind are unchanged.
 *
 * There is no instance and no property, so nothing here can be dumped,
 * serialized or printed. The one method that receives a password marks it
 * sensitive so a stack trace through it shows no credentials.
 */
final class LegacyConnectionRegistry
{
    private function __construct() {}

    /** The session key the helpers use when they are not given a connection. */
    public static function defaultKey(): string
    {
        [$hostname, $port, $database] = self::defaultTarget();

        return "$hostname:$port:$database";
    }

    /**
     * The parts of the default key, as assigned, for log lines that print them.
     *
     * @return array{0: mixed, 1: mixed, 2: mixed} hostname, port, database
     */
    public static function defaultTarget(): array
    {
        $hostname = &$GLOBALS['database_hostname'];
        $port = &$GLOBALS['database_port'];
        $database = &$GLOBALS['database_default'];

        return [$hostname, $port, $database];
    }

    /**
     * The connection a helper works on: the one it was given, or the default
     * session. A missing default key raises the same warning as reading the
     * array directly, and yields null.
     */
    public static function select(mixed $connection): mixed
    {
        if (\is_object($connection)) {
            return $connection;
        }

        return self::session(self::defaultKey());
    }

    public static function hasSession(string $key): bool
    {
        $sessions = &self::sessions();

        return isset($sessions[$key]);
    }

    public static function session(string $key): mixed
    {
        $sessions = &self::sessions();

        return $sessions[$key];
    }

    /** db_close() stores null here, which keeps the key present. */
    public static function setSession(string $key, mixed $connection): void
    {
        $sessions = &self::sessions();
        $sessions[$key] = $connection;
    }

    /** Points the default key at a handle, as the collector switches do. */
    public static function setDefaultSession(mixed $connection): void
    {
        self::setSession(self::defaultKey(), $connection);
    }

    /**
     * Registers a new session and the settings db_check_reconnect() reopens
     * it with, in the order db_connect_real() always wrote them.
     *
     * @param array<string, mixed> $details
     */
    public static function remember(string $key, \PDO $connection, #[\SensitiveParameter] array $details): void
    {
        self::setSession($key, $connection);
        $registered = &self::details();
        $registered[spl_object_hash($connection)] = $details;
    }

    /**
     * Every session's settings keyed by spl_object_hash() of its handle, or
     * null before the first connection.
     */
    public static function settings(): mixed
    {
        return self::details();
    }

    /** $database_persist: true from include/global.php unless config.php changes it, null where nothing assigned it. */
    public static function persist(): mixed
    {
        $persist = &$GLOBALS['database_persist'];

        return $persist;
    }

    /**
     * The collector's handles as include/global.php left them: a PDO, false
     * after a failed connection, or null when it never tried.
     *
     * @return array{local: mixed, remote: mixed}
     */
    public static function handles(): array
    {
        $remote = &$GLOBALS['remote_db_cnn_id'];
        $local = &$GLOBALS['local_db_cnn_id'];

        return ['local' => $local, 'remote' => $remote];
    }

    /**
     * Both tables are bound together, as the helpers that used one declared
     * the other, so neither is left undefined while the other exists.
     */
    private static function &sessions(): mixed
    {
        $GLOBALS['database_details'] ??= null;

        return $GLOBALS['database_sessions'];
    }

    private static function &details(): mixed
    {
        $GLOBALS['database_sessions'] ??= null;

        return $GLOBALS['database_details'];
    }
}
