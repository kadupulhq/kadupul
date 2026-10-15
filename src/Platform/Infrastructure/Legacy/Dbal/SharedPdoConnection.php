<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * DBAL's own PDO connection is internal and switches the handle to
 * ERRMODE_EXCEPTION for good, which would change how every later db_* call
 * on the same handle reports failure. This one raises exceptions only for the
 * duration of each call and puts the caller's mode back afterwards, whatever
 * the outcome, so the legacy helpers keep seeing ERRMODE_SILENT.
 */
final readonly class SharedPdoConnection implements DriverConnection
{
    public function __construct(private \PDO $pdo) {}

    /**
     * @template T
     *
     * @param \PDO $pdo the shared handle; statements report through it
     * @param \Closure(): T $operation
     *
     * @return T
     */
    public static function raising(\PDO $pdo, \Closure $operation): mixed
    {
        $mode = $pdo->getAttribute(\PDO::ATTR_ERRMODE);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            return $operation();
        } catch (\PDOException $exception) {
            throw SharedPdoFailure::from($exception);
        } finally {
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, $mode);
        }
    }

    #[\Override]
    public function prepare(string $sql): SharedPdoStatement
    {
        return new SharedPdoStatement($this->pdo, self::raising($this->pdo, fn(): \PDOStatement => $this->pdo->prepare($sql)));
    }

    #[\Override]
    public function query(string $sql): SharedPdoResult
    {
        return new SharedPdoResult($this->pdo, self::raising($this->pdo, fn(): \PDOStatement => $this->pdo->query($sql)));
    }

    #[\Override]
    public function quote(string $value): string
    {
        return self::raising($this->pdo, fn(): string => $this->pdo->quote($value));
    }

    #[\Override]
    public function exec(string $sql): int|string
    {
        return self::raising($this->pdo, fn(): int => $this->pdo->exec($sql));
    }

    #[\Override]
    public function lastInsertId(): int|string
    {
        $value = self::raising($this->pdo, fn(): string|false => $this->pdo->lastInsertId());
        // pdo_mysql answers '0' when the last statement generated no identity;
        // the driver contract asks for an exception rather than a fake id.
        if ($value === '0' || $value === '' || $value === false) {
            throw new SharedPdoFailure('No identity value was generated.');
        }

        return $value;
    }

    #[\Override]
    public function beginTransaction(): void
    {
        self::raising($this->pdo, fn(): bool => $this->pdo->beginTransaction());
    }

    #[\Override]
    public function commit(): void
    {
        self::raising($this->pdo, fn(): bool => $this->pdo->commit());
    }

    #[\Override]
    public function rollBack(): void
    {
        self::raising($this->pdo, fn(): bool => $this->pdo->rollBack());
    }

    #[\Override]
    public function getServerVersion(): string
    {
        return (string) $this->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    }

    #[\Override]
    public function getNativeConnection(): \PDO
    {
        return $this->pdo;
    }
}
