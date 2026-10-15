<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

final readonly class SharedPdoStatement implements Statement
{
    public function __construct(private \PDO $pdo, private \PDOStatement $statement) {}

    #[\Override]
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $native = match ($type) {
            ParameterType::NULL => \PDO::PARAM_NULL,
            ParameterType::INTEGER => \PDO::PARAM_INT,
            ParameterType::STRING, ParameterType::ASCII => \PDO::PARAM_STR,
            ParameterType::BINARY, ParameterType::LARGE_OBJECT => \PDO::PARAM_LOB,
            ParameterType::BOOLEAN => \PDO::PARAM_BOOL,
        };
        SharedPdoConnection::raising($this->pdo, fn(): bool => $this->statement->bindValue($param, $value, $native));
    }

    #[\Override]
    public function execute(): SharedPdoResult
    {
        SharedPdoConnection::raising($this->pdo, fn(): bool => $this->statement->execute());

        return new SharedPdoResult($this->pdo, $this->statement);
    }
}
