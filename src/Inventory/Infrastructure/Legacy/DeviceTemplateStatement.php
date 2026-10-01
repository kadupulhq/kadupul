<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

final class DeviceTemplateStatement
{
    public static function query(\PDO $db, string $sql): \PDOStatement
    {
        return self::confirmed($db->query($sql));
    }

    public static function prepare(\PDO $db, string $sql): \PDOStatement
    {
        $statement = $db->prepare($sql);
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Device template database operation was not confirmed.');
        }
        return $statement;
    }

    public static function execute(\PDOStatement $statement, array $parameters): void
    {
        if (!$statement->execute($parameters)) {
            throw new \RuntimeException('Device template database operation was not confirmed.');
        }
        self::confirmed($statement);
    }

    public static function fetch(\PDOStatement $statement, int $mode): mixed
    {
        $result = $statement->fetch($mode);
        self::confirmed($statement);
        return $result;
    }

    public static function fetchAll(\PDOStatement $statement, int $mode): array
    {
        $result = $statement->fetchAll($mode);
        self::confirmed($statement);
        return $result;
    }

    public static function fetchColumn(\PDOStatement $statement): mixed
    {
        $result = $statement->fetchColumn();
        self::confirmed($statement);
        return $result;
    }

    public static function insertedId(\PDO $db): int
    {
        $id = $db->lastInsertId();
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,7}$/D', $id) || (int) $id > 16777215 || $db->errorCode() !== '00000') {
            throw new \RuntimeException('Device template database operation was not confirmed.');
        }
        return (int) $id;
    }

    private static function confirmed(mixed $statement): \PDOStatement
    {
        if (!$statement instanceof \PDOStatement || $statement->errorCode() !== '00000') {
            throw new \RuntimeException('Device template database operation was not confirmed.');
        }
        return $statement;
    }
}
