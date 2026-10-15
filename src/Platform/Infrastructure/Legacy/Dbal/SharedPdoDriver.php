<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy\Dbal;

use Doctrine\DBAL\Driver\AbstractMySQLDriver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

/**
 * Hands DBAL the PDO that the legacy bootstrap already opened. Connection
 * parameters are ignored on purpose: this driver never dials, so it can
 * neither read credentials nor reach a server the legacy session cannot.
 */
final class SharedPdoDriver extends AbstractMySQLDriver
{
    public function __construct(private readonly \PDO $pdo) {}

    #[\Override]
    public function connect(#[\SensitiveParameter] array $params): DriverConnection
    {
        return new SharedPdoConnection($this->pdo);
    }
}
