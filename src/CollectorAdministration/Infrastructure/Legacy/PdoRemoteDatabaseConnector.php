<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Legacy;

use Kadupul\CollectorAdministration\Application\Port\RemoteDatabaseConnector;
use Kadupul\Platform\Contract\DatabaseTlsOptions;

final readonly class PdoRemoteDatabaseConnector implements RemoteDatabaseConnector
{
    public function __construct(private DatabaseTlsOptions $tls) {}

    public function connect(#[\SensitiveParameter] array $credentials): bool
    {
        $target = RemoteDatabaseTarget::fromCredentials($credentials, $this->tls);
        if ($target === null) {
            return false;
        }
        for ($attempt = 0; $attempt <= $target->retries; $attempt++) {
            try {
                $connection = new \PDO($target->dsn, $target->username, $target->password, $target->options);
                $connection->query('SELECT 1')->fetchColumn();
                return true;
            } catch (\Throwable) {
                // Driver exceptions can contain endpoints and credentials.
            }
        }
        return false;
    }
}
