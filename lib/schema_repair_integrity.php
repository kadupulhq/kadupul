<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Repair only the two schema contracts first added to the 1.2.31 step. */
function schema_repair_integrity(): bool
{
    global $database_sessions, $database_hostname, $database_port, $database_default, $database_last_error;
    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    try {
        if (!$db instanceof PDO || $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $db->inTransaction()) {
            throw new RuntimeException('Schema repair requires its established database connection without a caller transaction.');
        }
        SchemaRepairIntegrity::repair($db, static function (string $sql): void {
            db_install_add_cache(DB_STATUS_SUCCESS, $sql);
        });
        return true;
    } catch (Throwable $error) {
        // Earlier DDL can already be committed. Leave the version marker at
        // its confirmed retry boundary and make failed confirmation visible
        // to both CLI db_install_errors and Installer::checkDatabaseUpgrade.
        $database_last_error = __('Forward schema repair could not be confirmed. Review the target schema and installer privileges before retrying.');
        db_install_add_cache(DB_STATUS_ERROR, $database_last_error);
        return false;
    }
}

final class SchemaRepairIntegrity
{
    /** @param callable(string): void $completed */
    public static function repair(PDO $db, callable $completed): void
    {
        foreach (['data_input_data', 'aggregate_graphs'] as $table) {
            $create = self::rows($db, 'SHOW CREATE TABLE `' . $table . '`');
            $status = self::rows($db, "SHOW TABLE STATUS WHERE Name = '$table'");
            if (count($create) !== 1 || preg_match('/\ACREATE TABLE\s/i', (string) ($create[0]['Create Table'] ?? '')) !== 1
                || count($status) !== 1 || ($status[0]['Name'] ?? null) !== $table || ($status[0]['Engine'] ?? null) !== 'InnoDB') {
                throw new RuntimeException('The target persistent InnoDB table could not be confirmed.');
            }
        }
        $input = self::rows($db, 'SHOW FULL COLUMNS FROM `data_input_data`');
        if (!array_any($input, static fn(array $column): bool => ($column['Field'] ?? null) === 'data_input_field_id')) {
            throw new RuntimeException('The input field column is missing.');
        }
        $created = self::created($db);
        $indexes = self::index($db);
        // Validate both contracts before the first mutation, so unsupported
        // operator definitions never cause an unrelated partial repair.
        if ($indexes !== [] && !self::validIndex($indexes)) {
            throw new RuntimeException('The existing input field index requires manual review.');
        }
        if ($indexes === []) {
            self::execute($db, 'ALTER TABLE `data_input_data` ADD INDEX `data_input_field_id` (`data_input_field_id`)');
            if (!self::validIndex(self::index($db))) {
                throw new RuntimeException('The input field index was not stored as requested.');
            }
            $completed('ALTER TABLE `data_input_data` ADD INDEX `data_input_field_id` (`data_input_field_id`)');
        }
        if ($created) {
            $sql = 'ALTER TABLE `aggregate_graphs` MODIFY COLUMN `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP';
            self::execute($db, $sql);
            if (self::created($db)) {
                throw new RuntimeException('The created timestamp still updates automatically.');
            }
            $completed($sql);
        }
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $db, string $sql): array
    {
        $statement = $db->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Schema metadata is unavailable.');
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($statement->errorCode() !== '00000' || $db->errorCode() !== '00000'
            || !$statement->closeCursor() || $statement->errorCode() !== '00000') {
            throw new RuntimeException('Schema metadata could not be confirmed.');
        }
        return $rows;
    }

    private static function execute(PDO $db, string $sql): void
    {
        if ($db->inTransaction()) {
            throw new RuntimeException('Schema repair cannot commit caller-owned work.');
        }
        $statement = $db->prepare($sql);
        if ($statement === false || !$statement->execute() || $statement->errorCode() !== '00000' || $db->errorCode() !== '00000') {
            throw new RuntimeException('Schema repair DDL failed.');
        }
    }

    /** True for the recognized historical ON UPDATE shape, false for already repaired. */
    private static function created(PDO $db): bool
    {
        $columns = self::rows($db, 'SHOW FULL COLUMNS FROM `aggregate_graphs`');
        $column = array_find($columns, static fn(array $column): bool => ($column['Field'] ?? null) === 'created');
        $extra = strtolower(trim((string) preg_replace('/\bDEFAULT_GENERATED\b/i', '', (string) ($column['Extra'] ?? ''))));
        if ($column === null || !in_array(strtolower((string) $column['Type']), ['timestamp', 'timestamp(0)'], true)
            || $column['Null'] !== 'NO' || ($extra !== '' && ($column['Comment'] ?? '') !== '')
            || preg_match('/\Acurrent_timestamp(?:\(\))?\z/i', (string) $column['Default']) !== 1
            || !in_array($extra, ['', 'on update current_timestamp()', 'on update current_timestamp'], true)) {
            throw new RuntimeException('The created column requires manual review before repair.');
        }
        return $extra !== '';
    }

    /** @return list<array<string, mixed>> */
    private static function index(PDO $db): array
    {
        return array_values(array_filter(self::rows($db, 'SHOW INDEXES FROM `data_input_data`'), static fn(array $row): bool => ($row['Key_name'] ?? null) === 'data_input_field_id'));
    }

    /** @param list<array<string, mixed>> $indexes */
    private static function validIndex(array $indexes): bool
    {
        return count($indexes) === 1 && (int) $indexes[0]['Seq_in_index'] === 1
            && $indexes[0]['Column_name'] === 'data_input_field_id' && (int) $indexes[0]['Non_unique'] === 1
            && $indexes[0]['Sub_part'] === null && strtoupper((string) $indexes[0]['Index_type']) === 'BTREE'
            && $indexes[0]['Collation'] === 'A' && ($indexes[0]['Ignored'] ?? 'NO') === 'NO'
            && ($indexes[0]['Visible'] ?? 'YES') === 'YES';
    }
}
