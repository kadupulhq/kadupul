<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseCall;
use Kadupul\Platform\Infrastructure\Legacy\Dbal\LegacyDatabaseConnection;
use Kadupul\Platform\Infrastructure\Persistence\DbalConfigurationSettings;
use Kadupul\Platform\Infrastructure\Persistence\DbalUserSettings;

/**
 * The database half of the settings functions in lib/functions.php. Their
 * caches, defaults, log lines and collector pushes stay there; each method
 * here replaces one db_* call and answers what that call answered.
 *
 * The statement runs through the settings adapters on the session the helper
 * would have used, so it joins the caller's transaction and follows
 * db_switch_remote_to_main(). Three cases still call the helper:
 *
 * - There is no legacy session. DBAL has nothing to run on, and the helper
 *   reports that exactly as before.
 * - Doctrine DBAL cannot be loaded yet. include/global.php primes and reads
 *   settings before it loads Composer's autoloader, and loading it earlier
 *   would change the bootstrap order the CSRF boundary is tested against.
 * - An argument is outside the adapters' types: null, bool, float, array or
 *   object. What the helper does with those is recorded behavior (a NULL
 *   refused by a NOT NULL column, an array failed by its warning handler),
 *   and the adapters do not accept them.
 *
 * Failures are reported the helper's way rather than LegacyDatabaseCall's:
 * nothing is logged inside a transaction, in strict mode, or for a caller
 * that passes $log = false. read_config_option() passes false, and
 * cacti_log() itself calls read_config_option(), so logging that failure
 * would recurse for as long as the settings table cannot be read.
 */
final readonly class LegacySettingsStorage
{
    public function __construct(private LegacyLockRetry $retry = new LegacyLockRetry()) {}

    /**
     * db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', ...)
     *
     * @return array{value: string}|array{}|false
     */
    public function settingRow(mixed $name, bool $log = true): array|false
    {
        $pdo = self::session();
        if ($pdo === null || !is_string($name)) {
            return db_fetch_row_prepared('SELECT value FROM settings WHERE name = ?', [$name], $log);
        }
        $loud = self::helperWouldLog($pdo, $log);

        return $this->call($pdo, $loud)->row(function (Connection $connection) use ($pdo, $name, $loud): array|false {
            $value = $this->retry->run($loud, static fn(): ?string => (new DbalConfigurationSettings($connection))->value($name));
            self::selected($pdo, $value === null ? 0 : 1);

            return $value === null ? false : ['value' => $value];
        }, $loud, $pdo);
    }

    /** db_fetch_cell_prepared('SELECT COUNT(*) FROM settings WHERE name = ?', ...) > 0 */
    public function settingExists(mixed $name): bool
    {
        $pdo = self::session();
        if ($pdo === null || !is_string($name)) {
            return db_fetch_cell_prepared('SELECT COUNT(*) FROM settings WHERE name = ?', [$name]) > 0;
        }
        $loud = self::helperWouldLog($pdo, true);

        return $this->call($pdo, $loud)->cell(function (Connection $connection) use ($pdo, $name, $loud): bool {
            $exists = $this->retry->run($loud, static fn(): bool => (new DbalConfigurationSettings($connection))->exists($name));
            self::selected($pdo, 1);

            return $exists;
        }, $loud, $pdo) === true;
    }

    /**
     * array_rekey(db_fetch_assoc_prepared('SELECT name, value FROM settings
     * WHERE name IN (...)', ...), 'name', 'value'); a failure reads as no rows.
     *
     * @param list<string> $names
     *
     * @return array<string, string>
     */
    public function settingValues(array $names): array
    {
        $pdo = self::session();
        if ($pdo === null || array_any($names, static fn(mixed $name): bool => !is_string($name))) {
            $rows = db_fetch_assoc_prepared('SELECT name, value FROM settings WHERE name IN (' . implode(', ', array_fill(0, count($names), '?')) . ')', $names);

            return is_array($rows) ? array_column($rows, 'value', 'name') : [];
        }
        $loud = self::helperWouldLog($pdo, true);
        $values = $this->call($pdo, $loud)->row(function (Connection $connection) use ($pdo, $names, $loud): array {
            $values = $this->retry->run($loud, static fn(): array => (new DbalConfigurationSettings($connection))->values($names));
            // One row per stored name, which is what the helper counted.
            self::selected($pdo, count($values));

            return $values;
        }, $loud, $pdo);

        return $values === false ? [] : $values;
    }

    /** db_execute_prepared('INSERT INTO settings ... ON DUPLICATE KEY UPDATE ...', ...) */
    public function writeSetting(mixed $name, mixed $value): bool
    {
        $pdo = self::session();
        if ($pdo === null || !is_string($name) || !(is_string($value) || is_int($value))) {
            return db_execute_prepared('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$name, $value]);
        }
        $loud = self::helperWouldLog($pdo, true);

        return $this->call($pdo, $loud)->execute(fn(Connection $connection): int => $this->retry->run($loud, static fn(): int => (new DbalConfigurationSettings($connection))->write($name, $value)), $loud, $pdo);
    }

    /**
     * db_fetch_row_prepared('SELECT value FROM settings_user WHERE name = ?
     * AND user_id = ?', ...)
     *
     * @return array{value: string}|array{}|false
     */
    public function userSettingRow(mixed $name, mixed $userId): array|false
    {
        $pdo = self::session();
        if ($pdo === null || !is_string($name) || !(is_int($userId) || is_string($userId))) {
            return db_fetch_row_prepared('SELECT value FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);
        }
        $loud = self::helperWouldLog($pdo, true);

        return $this->call($pdo, $loud)->row(function (Connection $connection) use ($pdo, $name, $userId, $loud): array|false {
            $value = $this->retry->run($loud, static fn(): ?string => (new DbalUserSettings($connection))->value($userId, $name));
            self::selected($pdo, $value === null ? 0 : 1);

            return $value === null ? false : ['value' => $value];
        }, $loud, $pdo);
    }

    /**
     * db_fetch_cell_prepared('SELECT COUNT(*) FROM settings_user WHERE
     * name = ? AND user_id = ?', ...) > 0
     */
    public function userSettingExists(mixed $name, mixed $userId): bool
    {
        $pdo = self::session();
        if ($pdo === null || !is_string($name) || !(is_int($userId) || is_string($userId))) {
            $count = db_fetch_cell_prepared('SELECT COUNT(*) FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);

            return $count !== false && $count > 0;
        }
        $loud = self::helperWouldLog($pdo, true);

        return $this->call($pdo, $loud)->cell(function (Connection $connection) use ($pdo, $name, $userId, $loud): bool {
            $exists = $this->retry->run($loud, static fn(): bool => (new DbalUserSettings($connection))->exists($userId, $name));
            self::selected($pdo, 1);

            return $exists;
        }, $loud, $pdo) === true;
    }

    /** db_execute_prepared('INSERT INTO settings_user ... ON DUPLICATE KEY UPDATE ...', ...) */
    public function writeUserSetting(mixed $userId, mixed $name, mixed $value): bool
    {
        $pdo = self::session();
        if ($pdo === null || !(is_int($userId) || is_string($userId)) || !is_string($name) || !(is_string($value) || is_int($value))) {
            return db_execute_prepared('INSERT INTO settings_user (user_id, name, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$userId, $name, $value]);
        }
        $loud = self::helperWouldLog($pdo, true);

        return $this->call($pdo, $loud)->execute(fn(Connection $connection): int => $this->retry->run($loud, static fn(): int => (new DbalUserSettings($connection))->write($userId, $name, $value)), $loud, $pdo);
    }

    /** db_execute_prepared('DELETE FROM settings_user WHERE name = ? AND user_id = ?', ...) */
    public function removeUserSetting(mixed $name, mixed $userId): bool
    {
        $pdo = self::session();
        if ($pdo === null || !is_string($name) || !(is_int($userId) || is_string($userId))) {
            return db_execute_prepared('DELETE FROM settings_user WHERE name = ? AND user_id = ?', [$name, $userId]);
        }
        $loud = self::helperWouldLog($pdo, true);

        return $this->call($pdo, $loud)->execute(fn(Connection $connection): int => $this->retry->run($loud, static fn(): int => (new DbalUserSettings($connection))->remove($userId, $name)), $loud, $pdo);
    }

    /** The session to run DBAL on, or null when the helper has to run. */
    private static function session(): ?\PDO
    {
        static $loadable = false;
        // Once Composer's autoloader is in, it stays in.
        $loadable = $loadable || class_exists(Connection::class);

        return $loadable ? LegacyDatabaseConnection::select() : null;
    }

    /**
     * Whether db_execute_prepared() would log a failure and retry a lock
     * conflict: it throws first in strict mode, returns quietly inside a
     * transaction, and does neither for $log = false.
     */
    private static function helperWouldLog(\PDO $pdo, bool $log): bool
    {
        return $log && !$pdo->inTransaction() && !(defined('KADUPUL_THROW_DATABASE_ERRORS') && KADUPUL_THROW_DATABASE_ERRORS);
    }

    /**
     * A quiet call still sets db_error() and still throws in strict mode; it
     * only drops the log lines.
     */
    private function call(\PDO $pdo, bool $loud): LegacyDatabaseCall
    {
        // The helper counts every call, retried or not, and clears
        // db_affected_rows() before it runs the statement.
        $GLOBALS['database_total_queries'] = ($GLOBALS['database_total_queries'] ?? 0) + 1;
        $GLOBALS['affected_rows'][spl_object_hash($pdo)] = 0;

        return $loud ? new LegacyDatabaseCall() : new LegacyDatabaseCall(static function (): void {});
    }

    /**
     * After a read the helper leaves db_affected_rows() at the statement's
     * row count, which code that checks it after an UPDATE and a cold
     * read_config_option() in between has always seen.
     */
    private static function selected(\PDO $pdo, int $rows): void
    {
        $GLOBALS['affected_rows'][spl_object_hash($pdo)] = $rows;
    }
}
