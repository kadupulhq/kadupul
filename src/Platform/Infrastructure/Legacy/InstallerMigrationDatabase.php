<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

use Kadupul\Platform\Domain\Installer\MigrationStatus;

/** Execute release-owned upgrade operations through the exact native database session. */
final readonly class InstallerMigrationDatabase
{
    /** Keep schema helpers, driver outcomes and migration progress behind the native boundary. */
    public function __construct(private NativeInstallerFunctions $native) {}

    /** @param array<array-key, mixed> $params */
    public function execute(string $sql, array $params = [], bool $log = true): int
    {
        $status = ((bool) $this->native->call('db_execute_prepared', $sql, $params, $log) ? MigrationStatus::Success->value : MigrationStatus::Error->value);

        if ($log) {
            $this->native->call('db_install_add_cache', $status, $sql, $params);
        }

        return $status;
    }

    /** @param array<array-key, string>|string $columns */
    public function addKey(string $table, string $type, string $key, array|string $columns, string $using = ''): int
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        $type = strtoupper($type);
        if ($type === 'KEY' && $key === 'PRIMARY') {
            $sql = 'ALTER TABLE `' . $table . '` ADD ' . $key . ' ' . $type . '(' . implode(',', $columns) . ')';
        } else {
            $sql = 'ALTER TABLE `' . $table . '` ADD ' . $type . ' ' . $key . '(' . implode(',', $columns) . ')';
        }

        if ($using !== '' && $using !== '0') {
            $sql .= ' USING ' . $using;
        }

        $status = MigrationStatus::Skipped->value;
        if ($this->native->call('db_index_matches', $table, $key, $columns, false) !== 0) {
            if ((bool) $this->native->call('db_index_exists', $table, $key)) {
                $status = $this->dropKey($table, $type, $key);
            }

            if ($status !== MigrationStatus::Error->value) {
                $status = $this->execute($sql);
            }
        }

        $this->native->call('db_install_add_cache', $status, $sql);
        return $status;
    }

    /** Preserve primary and unique index removal grammar. */
    public function dropKey(string $table, string $type, string $key): int
    {
        $type = strtoupper(str_ireplace('UNIQUE ', '', $type));
        if ($type === 'KEY' && $key === 'PRIMARY') {
            $sql = "ALTER TABLE $table DROP $key $type;";
        } else {
            $sql = "ALTER TABLE $table DROP $type $key";
        }

        $status = MigrationStatus::Skipped->value;
        if ((bool) $this->native->call('db_index_exists', $table, $key, false)) {
            $status = $this->execute($sql);
        }

        $this->native->call('db_install_add_cache', $status, $sql);
        return $status;
    }

    /** Skip absent tables without reporting a write. */
    public function dropTable(string $table): int
    {
        $sql = 'DROP TABLE `' . $table . '`';

        $status = MigrationStatus::Skipped->value;
        if ((bool) $this->native->call('db_table_exists', $table, false)) {
            $status = $this->execute($sql, [], false) === MigrationStatus::Success->value ? MigrationStatus::Success->value : MigrationStatus::Error->value;
        }

        $this->native->call('db_install_add_cache', $status, $sql);
        return $status;
    }

    /** Rename only an existing source with an absent destination. */
    public function renameTable(string $table, string $newname): int
    {
        $sql = 'RENAME TABLE `' . $table . '` TO `' . $newname . '`';

        $status = MigrationStatus::Skipped->value;
        if ((bool) $this->native->call('db_table_exists', $table, false) && !(bool) $this->native->call('db_table_exists', $newname, false)) {
            $status = $this->execute($sql, [], false) === MigrationStatus::Success->value ? MigrationStatus::Success->value : MigrationStatus::Error->value;
        }

        $this->native->call('db_install_add_cache', $status, $sql);
        return $status;
    }

    /** Preserve the native column helper and its selected connection. */
    public function dropColumn(string $table, string $column): int
    {
        $sql = 'ALTER TABLE `' . $table . '` DROP `' . $column . '`';

        $status = MigrationStatus::Skipped->value;
        if ((bool) $this->native->call('db_column_exists', $table, $column, false)) {
            $status = (bool) $this->native->call('db_remove_column', $table, $column) ? MigrationStatus::Success->value : MigrationStatus::Error->value;
        }

        $this->native->call('db_install_add_cache', $status, $sql);
        return $status;
    }
    /**
     * Native scalar readers have one additional database-placeholder argument.
     * @param array<array-key, mixed> $params
     * @return array{status: int, data: mixed}
     */
    public function fetch(string $reader, string $sql, array $params = [], bool $log = true): array
    {
        if (!in_array($reader, ['db_fetch_assoc_prepared', 'db_fetch_row_prepared', 'db_fetch_cell_prepared'], true)) {
            throw new \InvalidArgumentException('Unsupported installation database reader.');
        }
        $this->native->call('db_install_reset_error');
        $data = $reader === 'db_fetch_cell_prepared'
            ? $this->native->call($reader, $sql, $params, '', $log)
            : $this->native->call($reader, $sql, $params, $log);
        $status = (bool) $this->native->call('db_error') ? MigrationStatus::Error->value : MigrationStatus::Success->value;
        if ($log || $status === MigrationStatus::Error->value) {
            $this->native->call('db_install_add_cache', $status, $sql, $params);
        }
        return ['status' => $status, 'data' => $data];
    }

    /**
     * The compatibility boundary binds a missing-table warning to native error state.
     * @param array{name: string, ...} $column
     * @return array{status: int, warning: ?string, sql: string}
     */
    public function addColumn(string $table, array $column, bool $ignore = true): array
    {
        $sql = 'ALTER TABLE `' . $table . '` ADD `' . $column['name'] . '`';
        $warning = null;
        if (!(bool) $this->native->call('db_table_exists', $table)) {
            $warning = "Table '" . $table . "' missing, cannot add column '" . $column['name'] . "'";
            $status = MigrationStatus::Warning->value;
        } elseif (!(bool) $this->native->call('db_column_exists', $table, $column['name'], false)) {
            $status = (bool) $this->native->call('db_add_column', $table, $column, false) ? MigrationStatus::Success->value : MigrationStatus::Error->value;
        } else {
            $status = $ignore ? MigrationStatus::Success->value : MigrationStatus::Skipped->value;
        }
        return ['status' => $status, 'warning' => $warning, 'sql' => $sql];
    }

    /** Keep failed reads from initiating a destructive setting-name replacement. */
    public function swapSetting(string $old, string $new): void
    {
        $exists = $this->fetch('db_fetch_cell_prepared', 'SELECT COUNT(*) FROM settings WHERE name = ?', [$new]);
        if ($exists['status'] !== MigrationStatus::Success->value) {
            throw new \RuntimeException('Installation setting lookup failed.');
        }
        if (!(bool) $exists['data']) {
            $this->execute('UPDATE `settings` SET name = ? WHERE name = ?', [$new, $old]);
        } else {
            $value = $this->fetch('db_fetch_cell_prepared', 'SELECT value FROM settings WHERE NAME = ?', [$old]);
            if ($value['status'] !== MigrationStatus::Success->value) {
                throw new \RuntimeException('Installation setting lookup failed.');
            }
            if ($this->execute('UPDATE `settings` SET value = ? WHERE name = ?', [$value['data'], $new]) !== MigrationStatus::Success->value) {
                throw new \RuntimeException('Installation setting replacement failed.');
            }
            $this->execute('DELETE FROM `settings` WHERE name = ?', [$old]);
        }
    }
}
