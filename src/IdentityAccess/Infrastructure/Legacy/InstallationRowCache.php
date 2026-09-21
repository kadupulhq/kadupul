<?php

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
    public function __construct(private readonly DatabaseConnection $database) {}

    public function invalidations(): iterable
    {
        $query = $this->database->get()->prepare('SELECT name, value FROM settings WHERE SUBSTRING(name, 1, 17) = ? ORDER BY name');
        $query->execute(['time_last_change_']);
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $value = (string) $row['value'];
            if (!ctype_digit($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                throw new \RuntimeException('Invalid row-cache invalidation timestamp.');
            }
            yield new RowCacheInvalidation(substr($row['name'], 17), (int) $value);
        }
    }

    public function remove(RowCacheInvalidation $invalidation): int
    {
        $pdo = $this->database->get();
        $select = $pdo->prepare('SELECT user_id, hash FROM user_auth_row_cache
            WHERE class = ? AND UNIX_TIMESTAMP(time) < ?
            ORDER BY user_id, hash LIMIT 1000');
        $select->bindValue(1, $invalidation->class);
        $select->bindValue(2, $invalidation->before, \PDO::PARAM_INT);
        $select->execute();
        $rows = $select->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            return 0;
        }
        // Recheck the cutoff: a concurrent refresh must survive deletion.
        $placeholders = implode(', ', array_fill(0, count($rows), '(?, ?)'));
        $delete = $pdo->prepare('DELETE FROM user_auth_row_cache
            WHERE class = ? AND UNIX_TIMESTAMP(time) < ?
            AND (user_id, hash) IN (' . $placeholders . ')');
        $delete->bindValue(1, $invalidation->class);
        $delete->bindValue(2, $invalidation->before, \PDO::PARAM_INT);
        $parameter = 3;
        foreach ($rows as $row) {
            $delete->bindValue($parameter++, (int) $row['user_id'], \PDO::PARAM_INT);
            $delete->bindValue($parameter++, $row['hash']);
        }
        $delete->execute();

        return $delete->rowCount();
    }
}
