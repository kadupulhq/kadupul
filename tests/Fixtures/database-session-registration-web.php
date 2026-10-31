<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Disposable web-SAPI fixture: native registration, PHP session engine and SQL.
ini_set('zend.exception_ignore_args', '1');
set_error_handler(static function (int $severity): bool {
    throw new RuntimeException('Native session registration warning: ' . $severity);
});
$database = new PDO(getenv('KADUPUL_SESSION_FIXTURE_DSN'), getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ?: (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'), getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') ?: (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
function db_column_exists(string $table, string $column): bool {
    $statement = $GLOBALS['database']->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $statement->execute([$table, $column]);
    return (int) $statement->fetchColumn() === 1;
}
function db_execute(string $sql): bool { return $GLOBALS['database']->exec($sql) !== false; }
function db_execute_prepared(string $sql, array $parameters = []): bool { return $GLOBALS['database']->prepare($sql)->execute($parameters); }
function db_fetch_cell_prepared(string $sql, array $parameters = []): mixed {
    $statement = $GLOBALS['database']->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}
function db_begin_transaction(): bool { return $GLOBALS['database']->beginTransaction(); }
function db_commit_transaction(): bool { return $GLOBALS['database']->commit(); }
function db_rollback_transaction(): bool { return $GLOBALS['database']->rollBack(); }
function get_client_addr(): string { return '192.0.2.42'; }
require dirname(__DIR__, 2) . '/include/session.php';
ini_set('session.use_cookies', '0');
session_id('native-owned-session');
if (!session_start()) throw new RuntimeException('Session startup failed.');
$action = $_GET['action'] ?? '';
if ($action === 'write') $_SESSION = ['sess_user_id' => 42, 'value' => 'native retained value'];
$identity = $_SESSION['sess_user_id'] ?? null;
$module = session_module_name();
if ($action === 'destroy') {
    if (!session_destroy()) throw new RuntimeException('Session destruction failed.');
} else {
    if ($action === 'gc' && session_gc() !== 1) throw new RuntimeException('Native GC acknowledgement changed.');
    if (!session_write_close()) throw new RuntimeException('Session close failed.');
}
header('Content-Type: application/json');
print json_encode(['module' => $module, 'actor' => $identity], JSON_THROW_ON_ERROR);
