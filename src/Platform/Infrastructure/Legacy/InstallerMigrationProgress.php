<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Domain\Installer\MigrationStatus;

/** Publish upgrade outcomes without exposing bound setting values in diagnostics. */
final readonly class InstallerMigrationProgress
{
    /** Reuse native progress timestamps and log hooks. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /**
     * Retain the version-indexed cache shared by web/CLI upgrade consumers.
     * @param array<array-key, mixed> $cache
     * @param array<array-key, mixed>|null $parameters
     */
    public function record(int $status, string $sql, ?array $parameters, string $version, mixed $error, array &$cache): void
    {
        $this->native->call('set_config_option', 'install_updated', microtime(true));
        print match ($status) {
            MigrationStatus::Skipped->value => '-', MigrationStatus::Success->value => '+', MigrationStatus::Warning->value => '!', MigrationStatus::Error->value => 'x', default => '?'
        };
        $query = $this->native->text($this->native->call('clean_up_lines', $sql));
        $expected = substr_count($query, '?');
        $actual = min($expected, count($parameters ?? []));
        if ($actual !== $expected) {
            $query .= "\n [[ WARNING: $expected parameters expected, $actual provided ]]";
        }
        // SQL and parameter count remain useful; bound values can include credentials.
        $file = $cache['file'] ?? '';
        if ($version === 'file') {
            throw new \RuntimeException('Invalid migration version identity.');
        }
        $cache[$version] ??= [];
        if (!is_array($cache[$version])) {
            throw new \RuntimeException('Invalid installation migration cache.');
        }
        $error = !(bool) $error ? $error : ($status === MigrationStatus::Error->value ? 'Database operation failed.' : 'Database operation diagnostic available.');
        $cache[$version][] = ['status' => $status, 'sql' => $query, 'error' => $error];
        if ($file === '' || $file === false) {
            return;
        }
        if (!is_string($file) || is_link($file) || !is_file($file)) {
            throw new \RuntimeException('The installation migration cache file is unavailable.');
        }
        $temporaryRoot = realpath(sys_get_temp_dir());
        $canonical = realpath($file);
        $stat = @stat($file);
        if ($temporaryRoot === false || $canonical === false || !str_starts_with($canonical, $temporaryRoot . DIRECTORY_SEPARATOR)
            || $stat === false || $stat['nlink'] !== 1) {
            throw new \RuntimeException('Invalid installation migration cache destination.');
        }
        $line = '<[version]> ' . $version . ' <[status]> ' . $status . ' <[sql]> ' . $this->native->text($this->native->call('clean_up_lines', $query)) . ' <[error]> ' . $this->native->text($error);
        $this->native->call('log_install_high', 'cache', $line);
        if (@file_put_contents($canonical, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== strlen($line . PHP_EOL)) {
            throw new \RuntimeException('The installation migration cache could not be written.');
        }
    }
}
