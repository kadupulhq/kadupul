<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Keep connection probes and temporary-table checks behind native driver semantics. */
final readonly class InstallerDatabaseDiagnostics
{
    /** Diagnostics never select a replacement for the caller's mutation connection. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /** @param list<mixed> $arguments Native host/user/password/database/TLS arguments in driver order. */
    public function connection(#[\SensitiveParameter] array $arguments): string
    {
        $connection = $this->native->call('db_connect_real', ...$arguments);
        $connected = is_object($connection);
        if ($connected) {
            $this->native->call('db_close', $connection);
        }
        return json_encode(['status' => $connected ? 'true' : 'false'], JSON_THROW_ON_ERROR);
    }

    /** Probe the selected session without leaving a persistent table behind. */
    public function temporaryTable(): bool
    {
        $table = 'test_temp_' . random_int(0, PHP_INT_MAX);
        if (!(bool) $this->native->call('db_execute', 'CREATE TEMPORARY TABLE ' . $table . ' (`cacti` char(20) NOT NULL DEFAULT "", PRIMARY KEY (`cacti`)) ENGINE=InnoDB')) {
            return false;
        }
        return (bool) $this->native->call('db_execute', 'DROP TABLE ' . $table);
    }
}
