<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs one db_update_table() index rebuild against a real database with the
// real lib/database.php and lib/functions.php, and prints what it observed.
// Arguments: the UNIQUE columns before, the JSON key columns after, and an
// optional directory for the child coverage report.
$root = dirname(__DIR__, 2);
if (isset($argv[3])) {
    define('DB_UPDATE_TABLE_UNIQUE_TEST_COVERAGE', true);
    define('RRD_TEST_COVERAGE_DIRECTORY', $argv[3]);
    require __DIR__ . '/rrd-process-coverage.php';
}
require_once $root . '/lib/database.php';
require_once $root . '/lib/functions.php';
$GLOBALS['config'] = [];
$GLOBALS['database_total_queries'] = 0;

$before = (string) $argv[1];
$after = json_decode((string) $argv[2], true, 4, JSON_THROW_ON_ERROR);
$db = new PDO((string) getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
$table = 'unique_rebuild_' . bin2hex(random_bytes(8));
$seen = ['created' => $db->exec("CREATE TABLE `$table` (a varchar(20) NOT NULL, b int NOT NULL, UNIQUE KEY pair_key ($before)) ENGINE=InnoDB") !== false];
try {
    $seen['seeded'] = $db->exec("INSERT INTO `$table` VALUES ('same',1),('same',2)") !== false;
    $definition = ['type' => 'InnoDB', 'columns' => [
        ['name' => 'a', 'type' => 'varchar(20)', 'NULL' => false],
        ['name' => 'b', 'type' => 'int', 'NULL' => false],
    ], 'keys' => [['name' => 'pair_key', 'columns' => $after]]];
    $seen['updated'] = db_update_table($table, $definition, false, false, $db);
    $indexes = $db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    $seen['non_unique'] = array_values(array_unique(array_map(static fn(array $row): int => (int) $row['Non_unique'], $indexes)));
    $seen['columns'] = array_map(static fn(array $row): string => $row['Column_name'], $indexes);
    $seen['duplicate'] = $db->exec("INSERT INTO `$table` VALUES ('same',1)");
    $seen['error'] = $db->errorCode();
    $seen['rows'] = (int) $db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
} finally {
    $seen['dropped'] = $db->exec("DROP TABLE `$table`") !== false;
}
echo json_encode($seen, JSON_THROW_ON_ERROR);
