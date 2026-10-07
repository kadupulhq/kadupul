<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

/** get_cacti_base_tables() (lib/functions.php:7589-7612) without the legacy bootstrap. */
final readonly class CactiSchemaFile
{
    public function __construct(private string $projectDir, private Filesystem $filesystem) {}

    /** @return list<string> every table cacti.sql declares, in file order; [] when the file is missing */
    public function baseTables(): array
    {
        try {
            $schema = $this->filesystem->readFile($this->projectDir . '/cacti.sql');
        } catch (IOException) {
            return [];
        }
        $tables = [];
        foreach (explode("\n", $schema) as $line) {
            if (str_contains($line, 'CREATE TABLE')) {
                $tables[] = trim(str_replace(['CREATE TABLE', '`', '(', ' '], '', $line));
            }
        }

        return $tables;
    }
}
