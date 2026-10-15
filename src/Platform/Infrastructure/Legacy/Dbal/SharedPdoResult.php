<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Exception\InvalidColumnIndex;

/**
 * Fetches go through the same error-mode switch: in ERRMODE_SILENT a failed
 * fetch reads as the end of the rows, which would turn an error into an
 * empty result.
 */
final readonly class SharedPdoResult implements Result
{
    public function __construct(private \PDO $pdo, private \PDOStatement $statement) {}

    #[\Override]
    public function fetchNumeric(): array|false
    {
        return $this->fetch(\PDO::FETCH_NUM);
    }

    #[\Override]
    public function fetchAssociative(): array|false
    {
        return $this->fetch(\PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function fetchOne(): mixed
    {
        return $this->fetch(\PDO::FETCH_COLUMN);
    }

    #[\Override]
    public function fetchAllNumeric(): array
    {
        return $this->fetchAll(\PDO::FETCH_NUM);
    }

    #[\Override]
    public function fetchAllAssociative(): array
    {
        return $this->fetchAll(\PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function fetchFirstColumn(): array
    {
        return $this->fetchAll(\PDO::FETCH_COLUMN);
    }

    #[\Override]
    public function rowCount(): int|string
    {
        return SharedPdoConnection::raising($this->pdo, fn(): int => $this->statement->rowCount());
    }

    #[\Override]
    public function columnCount(): int
    {
        return SharedPdoConnection::raising($this->pdo, fn(): int => $this->statement->columnCount());
    }

    public function getColumnName(int $index): string
    {
        try {
            $meta = SharedPdoConnection::raising($this->pdo, fn(): array|false => $this->statement->getColumnMeta($index));
        } catch (\ValueError $exception) {
            throw InvalidColumnIndex::new($index, $exception);
        }
        if ($meta === false) {
            throw InvalidColumnIndex::new($index);
        }

        return $meta['name'];
    }

    #[\Override]
    public function free(): void
    {
        SharedPdoConnection::raising($this->pdo, fn(): bool => $this->statement->closeCursor());
    }

    private function fetch(int $mode): mixed
    {
        return SharedPdoConnection::raising($this->pdo, fn(): mixed => $this->statement->fetch($mode));
    }

    /** @return list<mixed> */
    private function fetchAll(int $mode): array
    {
        return SharedPdoConnection::raising($this->pdo, fn(): array => $this->statement->fetchAll($mode));
    }
}
