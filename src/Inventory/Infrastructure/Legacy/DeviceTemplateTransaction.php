<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

final class DeviceTemplateTransaction
{
    public const AUTHORIZATION_TABLES = ['settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'];

    public static function begin(\PDO $db, array $configuration, array $tables): void
    {
        if ($db->inTransaction()) {
            throw new \RuntimeException('Caller-owned transaction.');
        }
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            if (!in_array($configuration['poller_id'] ?? null, [1, '1'], true)) {
                throw new \RuntimeException('Device templates require the primary collector.');
            }
            foreach (array_unique([...self::AUTHORIZATION_TABLES, ...$tables]) as $table) {
                if (!is_string($table) || !preg_match('/^[a-z_]+$/D', $table)) {
                    throw new \RuntimeException('Invalid storage table.');
                }
                $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
                if (!$query instanceof \PDOStatement) {
                    throw new \RuntimeException('Storage inspection was not confirmed.');
                }
                $row = $query->fetch(\PDO::FETCH_NUM);
                if (!is_array($row) || !is_string($row[1] ?? null) || !preg_match('/\n\) ENGINE=InnoDB(?:\s|$)/i', $row[1])) {
                    throw new \RuntimeException('Nontransactional storage.');
                }
            }
            if ($db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') === false) {
                throw new \RuntimeException('Transaction isolation was not confirmed.');
            }
        }
        if (!$db->beginTransaction()) {
            throw new \RuntimeException('Transaction start was not confirmed.');
        }
    }
    public static function commit(\PDO $db): void
    {
        if (!$db->inTransaction() || !$db->commit()) {
            throw new \RuntimeException('Transaction commit was not confirmed.');
        }
    }

    public static function rollback(\PDO $db): void
    {
        if (!$db->inTransaction() || !$db->rollBack()) {
            throw new \RuntimeException('Transaction rollback was not confirmed.');
        }
    }
}
