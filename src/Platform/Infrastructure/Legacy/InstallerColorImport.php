<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Import the release color catalog using bound data values on the selected database. */
final readonly class InstallerColorImport
{
    /** The trusted release directory controls the catalog source. */
    public function __construct(private string $projectDir, private NativeInstallerFunctions $native) {}

    /** Missing optional catalogs retain the legacy false result; failed reads/writes are errors. */
    public function import(): bool
    {
        $path = $this->projectDir . '/install/colors.csv';
        if (!file_exists($path)) {
            return false;
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('The installation color catalog could not be read.');
        }
        try {
            while (($row = fgetcsv($file, escape: '')) !== false) {
                if (count($row) !== 3 || !is_string($row[1]) || preg_match('/\A[0-9a-fA-F]{6}\z/D', $row[1]) !== 1 || !is_string($row[2])) {
                    throw new \RuntimeException('Invalid installation color record.');
                }
                [, $hex, $name] = $row;
                $name = trim($name);
                $exists = $this->native->call('db_fetch_cell_prepared', 'SELECT hex FROM colors WHERE hex = ?', [$hex]);
                if ((bool) $this->native->call('db_error')) {
                    throw new \RuntimeException('The installation color lookup failed.');
                }
                $sql = (bool) $exists ? "UPDATE colors SET name = ?, read_only = 'on' WHERE hex = ?"
                    : "INSERT IGNORE INTO colors (name, hex, read_only) VALUES (?, ?, 'on')";
                if ($this->native->call('db_execute_prepared', $sql, [$name, $hex]) === false) {
                    throw new \RuntimeException('The installation color catalog could not be stored.');
                }
            }
            if (!feof($file)) {
                throw new \RuntimeException('The installation color catalog was not completely read.');
            }
        } finally {
            fclose($file);
        }
        return true;
    }
}
