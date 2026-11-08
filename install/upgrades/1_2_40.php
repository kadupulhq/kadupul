<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function upgrade_to_1_2_40(): void
{
    $sql = file_get_contents(dirname(__DIR__) . '/asset-management.sql');
    if ($sql === false) {
        throw new RuntimeException('Asset schema is unavailable.');
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    if ($sql === null) {
        throw new RuntimeException('Asset schema is unreadable.');
    }
    $initialization = [];
    foreach (explode(';', $sql) as $statement) {
        if (str_starts_with(ltrim($statement), 'INSERT INTO `asset_registry`')) {
            $initialization[] = $statement;
            continue;
        }
        if (trim($statement) !== '') {
            db_install_execute($statement);
        }
    }
    // Catalog checks prevent IF NOT EXISTS from accepting a conflicting or partial schema.
    $expected = [
        'asset_registry' => ['id:int unsigned:NO:,revision:int unsigned:NO:,policy:longtext:NO:,sources:longtext:NO:', 'PRIMARY:0:1:id'],
        'asset_record' => ['kind:varchar(16):NO:,record_id:varchar(65):NO:,data:longtext:NO:', 'PRIMARY:0:1:kind,PRIMARY:0:2:record_id'],
        'asset_event' => ['sequence:bigint unsigned:NO:auto_increment,request_id:char(32):NO:,data:longtext:NO:', 'asset_request:0:1:request_id,PRIMARY:0:1:sequence'],
        'asset_observation' => ['sequence:bigint unsigned:NO:auto_increment,run_id:char(32):NO:,identity_id:char(64):NO:,data:longtext:NO:', 'asset_run_identity:0:1:run_id,asset_run_identity:0:2:identity_id,PRIMARY:0:1:sequence'],
    ];
    foreach ($expected as $table => [$columns, $indexes]) {
        $result = db_install_fetch_cell("SELECT CONCAT(ENGINE, ':', TABLE_COLLATION) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
        if (($result['data'] ?? null) !== 'InnoDB:utf8mb4_bin') {
            throw new RuntimeException('Asset table storage differs from the required definition.');
        }
        $result = db_install_fetch_cell("SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE, ':', EXTRA) ORDER BY ORDINAL_POSITION SEPARATOR ',') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
        $actual = preg_replace('/\b(int|bigint)\([0-9]+\)/', '$1', (string) ($result['data'] ?? ''));
        if ($actual !== $columns) {
            throw new RuntimeException('Asset table columns differ from the required definition.');
        }
        $result = db_install_fetch_cell("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND (COLUMN_DEFAULT IS NOT NULL OR (COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> 'utf8mb4_bin'))", [$table]);
        if ((string) ($result['data'] ?? '') !== '0') {
            throw new RuntimeException('Asset table defaults or collations differ from the required definition.');
        }
        $result = db_install_fetch_cell("SELECT GROUP_CONCAT(CONCAT(INDEX_NAME, ':', NON_UNIQUE, ':', SEQ_IN_INDEX, ':', COLUMN_NAME) ORDER BY BINARY INDEX_NAME, SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?", [$table]);
        $actual = explode(',', (string) ($result['data'] ?? ''));
        $wanted = explode(',', $indexes);
        sort($actual, SORT_STRING);
        sort($wanted, SORT_STRING);
        if ($actual !== $wanted) {
            throw new RuntimeException('Asset table indexes differ from the required definition.');
        }
        $result = db_install_fetch_cell("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND (SUB_PART IS NOT NULL OR INDEX_TYPE <> 'BTREE')", [$table]);
        if ((string) ($result['data'] ?? '') !== '0') {
            throw new RuntimeException('Asset index attributes differ from the required definition.');
        }
    }
    // Do not insert into an incompatible pre-existing table before validation.
    foreach ($initialization as $statement) {
        db_install_execute($statement);
    }
    $result = db_install_fetch_cell('SELECT COUNT(*) FROM asset_registry WHERE id = 1');
    if ((string) ($result['data'] ?? '') !== '1') {
        throw new RuntimeException('Asset registry initialization was not confirmed.');
    }
}
