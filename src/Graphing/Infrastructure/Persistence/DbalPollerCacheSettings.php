<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\PollerCacheSettings;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Reads the setting rebuild_poller_cache.php quoted in its --threads error, from the database the run works on. */
final readonly class DbalPollerCacheSettings implements PollerCacheSettings
{
    /** The default include/global_settings.php declares for commands_processes, which read_config_option() fell back to. */
    private const string DEFAULT = '1';

    /**
     * @param Connection $localConnection the installation's own database
     * @param Connection $mainConnection the main database, which a remote collector's run reads
     */
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    /**
     * @throws \Doctrine\DBAL\Exception when the settings table cannot be read
     */
    #[\Override]
    public function concurrentProcesses(OperatorDatabase $database): string
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
        $value = $connection->fetchOne('SELECT value FROM settings WHERE name = ?', ['commands_processes']);

        if ($value === false) {
            return self::DEFAULT;
        }

        // A NULL value reached the script as null, which its "?? 5" replaced.
        return is_scalar($value) ? (string) $value : '5';
    }
}
