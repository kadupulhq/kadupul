<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

final class BrowserAuthenticationSql
{
    /** @param list<mixed> $parameters */
    public static function execute(\PDO $database, string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $database->prepare($sql);
        if ($statement === false) {
            self::failure($database->errorInfo());
        }
        if (!$statement->execute($parameters)) {
            self::failure($statement->errorInfo());
        }
        self::confirmRead($statement);
        return $statement;
    }

    /** @return array<string, mixed>|false */
    public static function row(\PDOStatement $statement): array|false
    {
        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::confirmRead($statement);
        return $row;
    }

    public static function column(\PDOStatement $statement): mixed
    {
        $value = $statement->fetchColumn();
        self::confirmRead($statement);
        return $value;
    }

    public static function confirmConnection(\PDO $database): void
    {
        if ($database->errorCode() !== '00000') {
            self::failure($database->errorInfo());
        }
    }

    private static function confirmRead(\PDOStatement $statement): void
    {
        $state = $statement->errorCode();
        if ($state !== '00000') {
            self::failure($statement->errorInfo());
        }
    }

    /** @param array<mixed> $info */
    private static function failure(array $info): never
    {
        $failure = new \PDOException('Browser authentication persistence failed.');
        $failure->errorInfo = $info;
        throw $failure;
    }
}
