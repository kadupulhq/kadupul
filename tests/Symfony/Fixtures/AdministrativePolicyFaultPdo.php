<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use PDO;
use PDOStatement;

/** Executes real reads, then injects one unconfirmed driver outcome. */
final class AdministrativePolicyFaultPdo extends PDO
{
    public bool $injected = false;

    public function __construct(private PDO $connection, private string $fault) {}

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->connection->getAttribute($attribute);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $statement = $this->connection->prepare($query, $options);
        $metadata = str_contains($query, 'information_schema.tables');
        $actor = str_starts_with($query, 'SELECT username, password');
        if ($statement !== false && (($metadata && $this->fault === 'metadata_shape')
            || ($actor && in_array($this->fault, ['late_fetch', 'scalar_row', 'numeric_column', 'late_close'], true)))) {
            $this->injected = true;
            return new AdministrativePolicyFaultStatement($statement, $this->fault);
        }
        return $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_starts_with($query, 'SHOW CREATE TABLE') && $this->fault === 'definition_false') {
            $this->injected = true;
            return false;
        }
        $statement = $fetchMode === null ? $this->connection->query($query) : $this->connection->query($query, $fetchMode, ...$fetchModeArgs);
        if ($statement !== false && str_starts_with($query, 'SHOW CREATE TABLE') && $this->fault === 'definition_close') {
            $this->injected = true;
            return new AdministrativePolicyFaultStatement($statement, $this->fault);
        }
        return $statement;
    }
}

final class AdministrativePolicyFaultStatement extends PDOStatement
{
    private bool $fetched = false;

    public function __construct(private PDOStatement $statement, private string $fault) {}

    public function execute(?array $params = null): bool
    {
        return $this->statement->execute($params);
    }

    public function errorCode(): ?string
    {
        return $this->fetched && $this->fault === 'late_fetch' ? 'HY000' : $this->statement->errorCode();
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = $this->statement->fetchAll($mode, ...$args);
        $this->fetched = true;
        return match ($this->fault) {
            'scalar_row' => ['unconfirmed-row'],
            'numeric_column' => [[0 => 'unconfirmed-column']],
            'metadata_shape' => [['name' => null, 'engine' => 'InnoDB']],
            default => $rows,
        };
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->statement->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function closeCursor(): bool
    {
        $closed = $this->statement->closeCursor();
        return in_array($this->fault, ['late_close', 'definition_close'], true) ? false : $closed;
    }
}
