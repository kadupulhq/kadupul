<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** One legacy permission statement and its epochs share one rollback boundary. */
final class PermissionMutation
{
    private static ?PDO $batchConnection = null;
    /** @var list<string> */
    private static array $batchTables = array();
    /** @var array<int, true> */
    private static array $batchGroups = array();
    /** @var array<int, true> */
    private static array $batchInvalidated = array();
    /** @var array<int, list<int>> */
    private static array $batchMembers = array();

    /** Keep successful selection units, undo failed units, and publish the batch together.
     *  @param list<string> $tables
     *  @param list<int> $groups
     */
    public static function batch(array $tables, array $groups, callable $operation): void
    {
        if (self::$batchConnection !== null) {
            throw new RuntimeException('Nested permission batch is not supported.');
        }
        $db = self::connection();
        $owned = !$db->inTransaction();
        $savepoint = 'kadupul_permission_batch_' . bin2hex(random_bytes(12));
        if ($owned) {
            if (!$db->beginTransaction()) {
                throw new RuntimeException('Could not begin permission batch.');
            }
        } else {
            self::control($db, 'SAVEPOINT ' . $savepoint);
        }
        try {
            self::assertTables($db, $tables);
            sort($groups, SORT_NUMERIC);
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            foreach (array_unique($groups) as $group) {
                self::rows($db, 'SELECT id FROM user_auth_group WHERE id = ?' . $lock, array($group));
                self::$batchGroups[$group] = true;
            }
            self::$batchConnection = $db;
            self::$batchTables = $tables;
            $operation();
            if ($owned) {
                if (!$db->commit()) {
                    throw new RuntimeException('Permission batch commit failed.');
                }
            } else {
                self::control($db, 'RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $error) {
            try {
                self::rollback($db, $owned, $savepoint);
            } catch (Throwable) {
                throw $error;
            }
            throw $error;
        } finally {
            self::$batchConnection = null;
            self::$batchTables = self::$batchGroups = self::$batchInvalidated = self::$batchMembers = array();
        }
    }

    /** @param list<mixed> $parameters */
    public static function write(string $sql, array $parameters, bool $group, int $principal, ?int $member = null): bool
    {
        $db = self::connection();
        $owned = !$db->inTransaction();
        $savepoint = 'kadupul_permission_' . bin2hex(random_bytes(12));
        if ($owned) {
            if (!$db->beginTransaction()) {
                throw new RuntimeException('Could not begin permission mutation.');
            }
        } else {
            self::control($db, 'SAVEPOINT ' . $savepoint);
        }

        try {
            $table = self::mutationTable($sql);
            $tables = array_unique(array('user_auth', $table, ...($group || $table === 'user_auth_group_members' ? array('user_auth_group', 'user_auth_group_members') : array())));
            if (self::$batchConnection !== null) {
                if (self::$batchConnection !== $db || array_diff($tables, self::$batchTables) !== array()) {
                    throw new RuntimeException('Permission batch connection or tables changed.');
                }
            } else {
                self::assertTables($db, $tables);
            }
            $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $users = array($principal);
            // Both membership orientations serialize with group-policy changes.
            $membershipGroup = !$group && $table === 'user_auth_group_members' ? (int) $parameters[1] : null;
            if ($membershipGroup !== null && !isset(self::$batchGroups[$membershipGroup])) {
                self::rows($db, 'SELECT id FROM user_auth_group WHERE id = ?' . $lock, array($membershipGroup));
            }
            if ($group) {
                if (!isset(self::$batchGroups[$principal])) {
                    self::rows($db, 'SELECT id FROM user_auth_group WHERE id = ?' . $lock, array($principal));
                }
                if ($member !== null) {
                    $users = array($member);
                } else {
                    $users = self::$batchMembers[$principal] ?? array_map('intval', array_column(self::rows($db, 'SELECT user_id FROM user_auth_group_members WHERE group_id = ? ORDER BY user_id' . $lock, array($principal)), 'user_id'));
                    if (self::$batchConnection === $db) {
                        self::$batchMembers[$principal] = $users;
                    }
                }
            }
            $users = array_values(array_unique(array_map('intval', $users)));
            sort($users, SORT_NUMERIC);
            $epochs = array();
            $users = array_values(array_filter($users, static fn(int $user): bool => !isset(self::$batchInvalidated[$user])));
            foreach (array_chunk($users, 1000) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                foreach (self::rows($db, 'SELECT id, reset_perms FROM user_auth WHERE id IN (' . $placeholders . ') ORDER BY id' . $lock, $chunk) as $row) {
                    $epoch = self::epoch($row['reset_perms']);
                    $epochs[(int) $row['id']] = $epoch === 4294967295 ? 1 : $epoch + 1;
                }
            }

            // Pass the very same PDO to the legacy driver, including its false-on-error contract.
            if (!\db_execute_prepared($sql, $parameters, true, $db)) {
                self::rollback($db, $owned, $savepoint);
                return false;
            }
            if (strncmp($sql, 'DELETE', 6) === 0) {
                // Read the receipt from this exact PDO before another statement can replace it.
                $affected = \db_affected_rows($db);
                if (!is_int($affected) || $affected < 0) {
                    throw new PermissionEpochFailure('Permission delete outcome was not confirmed.');
                }
                if ($affected === 0) {
                    if ($owned) {
                        if (!$db->commit()) {
                            throw new RuntimeException('Permission mutation commit failed.');
                        }
                    } else {
                        self::control($db, 'RELEASE SAVEPOINT ' . $savepoint);
                    }
                    return true;
                }
            }
            foreach (array_chunk($epochs, 1000, true) as $chunk) {
                $ids = array_keys($chunk);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $query = $db->prepare('UPDATE user_auth SET reset_perms = CASE WHEN reset_perms = 4294967295 THEN 1 ELSE reset_perms + 1 END WHERE id IN (' . $placeholders . ')');
                if ($query === false || !$query->execute($ids)) {
                    throw new PermissionEpochFailure('Permission epoch update failed.');
                }
                $stored = self::rows($db, 'SELECT id, reset_perms FROM user_auth WHERE id IN (' . $placeholders . ') ORDER BY id', $ids);
                if (count($stored) !== count($chunk)) {
                    throw new PermissionEpochFailure('Permission epoch update was not confirmed.');
                }
                foreach ($stored as $row) {
                    if (!isset($chunk[(int) $row['id']]) || self::epoch($row['reset_perms']) !== $chunk[(int) $row['id']]) {
                        throw new PermissionEpochFailure('Permission epoch update was not confirmed.');
                    }
                }
            }
            if ($owned) {
                if (!$db->commit()) {
                    throw new RuntimeException('Permission mutation commit failed.');
                }
            } else {
                self::control($db, 'RELEASE SAVEPOINT ' . $savepoint);
            }
        } catch (Throwable $error) {
            try {
                self::rollback($db, $owned, $savepoint);
            } catch (Throwable) {
                // Preserve the original failure; never commit or roll back caller-owned work.
                throw $error;
            }
            if ($error instanceof PDOException || $error instanceof PermissionEpochFailure) {
                return false;
            }
            throw $error;
        }

        if (self::$batchConnection === $db) {
            foreach (array_keys($epochs) as $user) {
                self::$batchInvalidated[$user] = true;
            }
        }

        // Match the existing direct-user reset's local cache behavior; group resets
        // are detected by the persisted epoch on the next authorization check.
        $direct = $group ? $member : $principal;
        if ($direct !== null && $direct === (int) ($_SESSION['sess_user_id'] ?? 0)) {
            foreach (array('sess_user_realms', 'sess_user_config_array', 'sess_config_array', 'sess_auth_names') as $name) {
                \kill_session_var($name);
            }
        }
        return true;
    }

    private static function connection(): PDO
    {
        global $database_sessions, $database_hostname, $database_port, $database_default;
        $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
        if (!$db instanceof PDO) {
            throw new RuntimeException('Permission mutation requires the active legacy PDO.');
        }
        return $db;
    }

    private static function mutationTable(string $sql): string
    {
        if (!preg_match('/^(?:REPLACE INTO|DELETE FROM|UPDATE)\s+`?(user_auth_group_members|user_auth_group_perms|user_auth_perms|user_auth_group|user_auth)`?\b/i', $sql, $match)) {
            throw new RuntimeException('Unsupported permission mutation statement.');
        }
        return $match[1];
    }

    /** @param list<string> $tables */
    private static function assertTables(PDO $db, array $tables): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            return;
        }
        if ($driver !== 'mysql') {
            throw new RuntimeException('Unsupported permission database driver.');
        }
        foreach ($tables as $table) {
            if (!in_array($table, array('user_auth', 'user_auth_group', 'user_auth_group_members', 'user_auth_perms', 'user_auth_group_perms'), true)) {
                throw new RuntimeException('Unsupported permission table.');
            }
            // A transactional SELECT retains metadata locks before inspecting the
            // actual session table, rather than trusting catalog data that ignores shadows.
            self::rows($db, 'SELECT * FROM `' . $table . '` LIMIT 0', array());
            $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
            $row = $query === false ? false : $query->fetch(PDO::FETCH_NUM);
            if (!is_array($row) || !isset($row[1]) || stripos($row[1], 'CREATE TEMPORARY TABLE') !== false || !preg_match('/\bENGINE=InnoDB\b/i', $row[1])) {
                throw new RuntimeException('Permission mutation requires persistent InnoDB tables.');
            }
        }
    }

    /** @param list<mixed> $parameters
     *  @return list<array<string, mixed>>
     */
    private static function rows(PDO $db, string $sql, array $parameters): array
    {
        $query = $db->prepare($sql);
        if ($query === false || !$query->execute($parameters)) {
            throw new PermissionEpochFailure('Permission mutation read failed.');
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function epoch(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || !ctype_digit($value))) {
            throw new PermissionEpochFailure('Invalid persisted permission epoch.');
        }
        if ((float) $value < 0 || (float) $value > 4294967295) {
            throw new PermissionEpochFailure('Invalid persisted permission epoch.');
        }
        return (int) $value;
    }

    private static function control(PDO $db, string $sql): void
    {
        if ($db->exec($sql) === false) {
            throw new RuntimeException('Permission transaction control failed.');
        }
    }

    private static function rollback(PDO $db, bool $owned, string $savepoint): void
    {
        if (!$db->inTransaction()) {
            throw new RuntimeException('Permission transaction was lost.');
        }
        if ($owned) {
            if (!$db->rollBack()) {
                throw new RuntimeException('Permission mutation rollback failed.');
            }
        } else {
            self::control($db, 'ROLLBACK TO SAVEPOINT ' . $savepoint);
            self::control($db, 'RELEASE SAVEPOINT ' . $savepoint);
        }
    }
}

/** Internal unsuccessful outcome, returned only after the unit has been undone. */
final class PermissionEpochFailure extends RuntimeException {}
