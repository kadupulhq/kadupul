<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Port\InvalidatedRowCache;
use Kadupul\IdentityAccess\Domain\RowCacheInvalidation;
use Kadupul\Platform\Contract\DatabaseConnection;

final class InstallationRowCache implements InvalidatedRowCache
{
    private ?\PDO $connection = null;

    public function __construct(private readonly DatabaseConnection $database) {}

    public function invalidations(): iterable
    {
        $query = $this->connection()->prepare('SELECT name, value FROM settings WHERE SUBSTRING(name, 1, 17) = ? ORDER BY name');
        $query->execute(['time_last_change_']);
        /** @var list<array<string, mixed>> $rows */
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $rawValue = $row['value'] ?? null;
            $rawName = $row['name'] ?? null;
            $value = is_scalar($rawValue) ? (string) $rawValue : '';
            if (!is_string($rawName) || !ctype_digit($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('Invalid row-cache invalidation timestamp.');
            }
            yield new RowCacheInvalidation(substr($rawName, 17), (int) $value);
        }
    }

    public function count(RowCacheInvalidation $invalidation): int
    {
        $query = $this->connection()->prepare('SELECT COUNT(*) FROM user_auth_row_cache WHERE class = ? AND time < FROM_UNIXTIME(?)');
        $query->bindValue(1, $invalidation->class);
        $query->bindValue(2, $invalidation->before, \PDO::PARAM_INT);
        $query->execute();
        $count = $query->fetchColumn();

        return is_int($count) || (is_string($count) && is_numeric($count)) ? (int) $count : 0;
    }

    public function remove(RowCacheInvalidation $invalidation): int
    {
        $pdo = $this->connection();
        $select = $pdo->prepare('SELECT user_id, hash FROM user_auth_row_cache
            WHERE class = ? AND time < FROM_UNIXTIME(?)
            ORDER BY time, user_id, hash LIMIT 1000');
        $select->bindValue(1, $invalidation->class);
        $select->bindValue(2, $invalidation->before, \PDO::PARAM_INT);
        $select->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }
        // Recheck the cutoff: a concurrent refresh must survive deletion.
        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?)'));
        $delete = $pdo->prepare('DELETE FROM user_auth_row_cache
            WHERE class = ? AND time < FROM_UNIXTIME(?)
            AND (user_id, hash) IN (' . $placeholders . ')');
        $delete->bindValue(1, $invalidation->class);
        $delete->bindValue(2, $invalidation->before, \PDO::PARAM_INT);
        $parameter = 3;
        foreach ($rows as $row) {
            $rawUserId = $row['user_id'] ?? 0;
            $userId = is_int($rawUserId) || (is_string($rawUserId) && is_numeric($rawUserId)) ? (int) $rawUserId : 0;
            $hash = is_string($row['hash'] ?? null) ? $row['hash'] : '';
            $delete->bindValue($parameter++, $userId, \PDO::PARAM_INT);
            $delete->bindValue($parameter++, $hash);
        }
        $delete->execute();

        return $delete->rowCount();
    }

    private function connection(): \PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        $pdo = $this->database->get();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Dedicated cleanup connection: epoch cutoffs stay unambiguous at DST.
            $pdo->exec("SET time_zone = '+00:00'");
            $indexQuery = $pdo->query("SHOW INDEX FROM user_auth_row_cache WHERE Key_name = 'class_time'");
            $index = $indexQuery === false ? [] : $indexQuery->fetchAll(\PDO::FETCH_ASSOC);
            if (array_column($index, 'Column_name') !== ['class', 'time']) {
                throw new \RuntimeException('Row-cache cleanup requires the class_time index. Complete the database upgrade first.');
            }
        }

        return $this->connection = $pdo;
    }
}
