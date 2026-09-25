<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Clock\ClockInterface;
use Symfony\Component\Filesystem\Filesystem;

/** get_cacti_cli_version() without the legacy bootstrap. */
final readonly class InstallationVersion
{
    public function __construct(private string $projectDir, private Connection $localConnection, private Filesystem $filesystem, private ClockInterface $clock) {}

    /** include/cacti_version, trimmed, as include/global.php:24-32 defined CACTI_VERSION. */
    public function file(): string
    {
        return trim($this->filesystem->readFile($this->projectDir . '/include/cacti_version'));
    }

    /** "<include/cacti_version> (DB: <version.cacti>)" */
    public function text(): string
    {
        $file = $this->file();
        // get_cacti_version() reads the default (local) connection through
        // db_fetch_cell(), which yields an empty cell rather than failing.
        try {
            $db = trim((string) $this->localConnection->fetchOne('SELECT cacti FROM version LIMIT 1'));
        } catch (Exception) {
            $db = '';
        }

        return $file . ' (DB: ' . $db . ')';
    }

    /**
     * The first line of every cli/ script's --version and --help output. The
     * year comes from the clock, not date(), so tests can fix it.
     */
    public function line(string $utility): string
    {
        return $utility . ', Version ' . $this->text() . ', Copyright (C) 2004-' . $this->clock->now()->format('Y') . ' The Cacti Group';
    }
}
