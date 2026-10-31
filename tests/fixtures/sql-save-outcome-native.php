<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

if (PHP_SAPI !== 'cli' || !getenv('KADUPUL_TEST_MYSQL_DSN')) exit(64);
$root = dirname(__DIR__, 2);
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/tests/Helpers/PhpSource.php';
$functions = file_get_contents($root . '/lib/functions.php');
if ($functions === false) throw new RuntimeException('Cannot read database support functions');
foreach (['cacti_sizeof', 'cacti_count', 'clean_up_lines', 'cacti_is_sensitive_key'] as $name) eval(test_php_function_source($functions, $name));
function cacti_log($message, $output = false, $environment = 'CMDPHP', $level = '') { return true; }
function cacti_debug_backtrace($entry = '', $html = false, $record = true, $limit = 0, $skip = 0) { return ''; }
function raise_message($key, $message = '', $level = '') { $GLOBALS['messages'][] = $key; }

$mode = $argv[1] ?? '';
if (!in_array($mode, ['update', 'unchanged', 'rejected-update', 'insert', 'rejected-insert'], true)) exit(64);
parse_str(str_replace(';', '&', substr((string) getenv('KADUPUL_TEST_MYSQL_DSN'), 6)), $dsn);
$prefix = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') ? 'KADUPUL_TEST_MYSQL_ADMIN_' : 'KADUPUL_TEST_MYSQL_';
$user = getenv($prefix . 'USER') ?: 'root';
$password = getenv($prefix . 'PASSWORD') ?: '';
$database_hostname = (string) $dsn['host'];
$database_port = (string) ($dsn['port'] ?? '3306');
$database_default = 'knull_' . bin2hex(random_bytes(6));
$target = $database_default . '_target';
$admin = new PDO('mysql:host=' . $database_hostname . ';port=' . $database_port . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$owned = [];
$config = ['poller_id' => 1];
$messages = [];
$out = [];
try {
	foreach ([$database_default, $target] as $schema) {
		$admin->exec("CREATE DATABASE `$schema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
		$owned[] = $schema;
	}
	$default = db_connect_real($database_hostname, $user, $password, $database_default, 'mysql', $database_port, 1);
	$db = db_connect_real($database_hostname, $user, $password, $target, 'mysql', $database_port, 1);
	$password = null;
	if (!$db instanceof PDO || !$default instanceof PDO) throw new RuntimeException('Native database connection failed');
	foreach ([$default, $db] as $connection) {
		$connection->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
		$connection->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
		$session = $connection->query('SELECT @@character_set_connection AS charset, @@sql_mode AS sql_mode')->fetch(PDO::FETCH_ASSOC);
		if ($session['charset'] !== 'utf8mb4' || !str_contains($session['sql_mode'], 'STRICT_ALL_TABLES')) throw new RuntimeException('Database session settings were not applied');
	}
	$out['server'] = $db->query('SELECT VERSION()')->fetchColumn();
	foreach ([$default, $db] as $index => $connection) {
		$connection->exec("CREATE TABLE metric (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, value INT NOT NULL, label VARCHAR(20) NOT NULL DEFAULT '', CHECK (value >= 0)) ENGINE=InnoDB");
		$connection->exec('INSERT INTO metric (id,value) VALUES (1,' . (41 + $index) . ')');
	}
	$db->beginTransaction();
	$db->exec("UPDATE metric SET label='owned' WHERE id=1");
	$id = in_array($mode, ['insert', 'rejected-insert'], true) ? 0 : 1;
	$value = str_starts_with($mode, 'rejected') ? -1 : ($mode === 'unchanged' ? 42 : 43);
	$out['result'] = sql_save(['id' => $id, 'value' => $value], 'metric', 'id', true, $db);
	$out['caller_transaction'] = $db->inTransaction();
	$out['rows'] = $db->query('SELECT id,value,label FROM metric ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	$out['default_unchanged'] = $default->query('SELECT value FROM metric WHERE id=1')->fetchColumn();
} finally {
	if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
	foreach (array_reverse($owned) as $schema) $admin->exec("DROP DATABASE `$schema`");
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), PHP_EOL;
