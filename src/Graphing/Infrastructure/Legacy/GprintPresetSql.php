<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Legacy;

/** Confirm PDO results even when the caller selected silent error handling. */
final class GprintPresetSql
{
    public static function execute(\PDO $database, string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $database->prepare($sql);
        if ($statement === false) {
            self::failure('GPRINT database operation could not be confirmed.');
        }
        if (!$statement->execute($parameters)) {
            self::failure('GPRINT database operation could not be confirmed.');
        }
        return $statement;
    }

    public static function one(\PDOStatement $statement, int $mode = \PDO::FETCH_ASSOC): array|false
    {
        $row = $statement->fetch($mode);
        self::confirmRead($statement);
        return $row;
    }

    public static function column(\PDOStatement $statement): mixed
    {
        $value = $statement->fetchColumn();
        self::confirmRead($statement);
        return $value;
    }

    private static function confirmRead(\PDOStatement $statement): void
    {
        if ($statement->errorCode() !== '00000') {
            self::failure('GPRINT database result could not be confirmed.');
        }
    }

    private static function failure(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
