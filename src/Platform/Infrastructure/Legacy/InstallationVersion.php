<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Component\Filesystem\Filesystem;

/** get_cacti_cli_version() without the legacy bootstrap. */
final readonly class InstallationVersion
{
    public function __construct(private string $projectDir, private Connection $localConnection, private Filesystem $filesystem) {}

    /** "<include/cacti_version> (DB: <version.cacti>)" */
    public function text(): string
    {
        $file = trim($this->filesystem->readFile($this->projectDir . '/include/cacti_version'));
        // get_cacti_version() reads the default (local) connection through
        // db_fetch_cell(), which yields an empty cell rather than failing.
        try {
            $db = trim((string) $this->localConnection->fetchOne('SELECT cacti FROM version LIMIT 1'));
        } catch (Exception) {
            $db = '';
        }

        return $file . ' (DB: ' . $db . ')';
    }
}
