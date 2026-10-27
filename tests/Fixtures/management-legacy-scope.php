<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$schema = getenv('KADUPUL_MANAGEMENT_TEST_SCHEMA');
$dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
if (!is_string($schema) || !preg_match('/\Amanagement_test_[a-f0-9]{16}\z/D', $schema) || !is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('A disposable management-test schema is required.');
}
$dsn = preg_replace('/;dbname=[^;]*/', '', $dsn) . ';dbname=' . $schema;
$database = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
require dirname(__DIR__, 2) . '/lib/auth.php';
function db_fetch_assoc_prepared($sql, $parameters = []) {
    global $database;
    $query = $database->prepare($sql); $query->execute($parameters); return $query->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_row_prepared($sql, $parameters = []) { return db_fetch_assoc_prepared($sql, $parameters)[0] ?? []; }
function db_fetch_cell_prepared($sql, $parameters = []) {
    global $database;
    $query = $database->prepare($sql); $query->execute($parameters); return $query->fetchColumn();
}
function read_config_option($name, ...$unused) { return db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', [$name]); }
function cacti_sizeof($items) { return is_array($items) ? count($items) : 0; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function cacti_log(...$arguments) { throw new RuntimeException('Unexpected authentication log in admitted fixture.'); }
$_SESSION = ['sess_user_id' => 42];
$graphs = get_allowed_management_graph_ids_sql(42);
$devices = get_allowed_management_device_ids_sql(42);
echo json_encode(['graphs' => $database->query($graphs . ' ORDER BY gl.id')->fetchAll(PDO::FETCH_COLUMN), 'devices' => $database->query($devices . ' ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)], JSON_THROW_ON_ERROR);
