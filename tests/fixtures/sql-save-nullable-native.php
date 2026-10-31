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
if (!in_array($mode, ['values', 'defaults', 'updates', 'failure', 'refusal', 'negative-refusal', 'schema'], true)) exit(64);
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
	if ($mode === 'values') {
		$types = ['tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'float', 'double', 'decimal(20,4)'];
		$inputs = ['empty' => '', 'null' => null, 'zero' => 0, 'string-zero' => '0', 'false' => false,
			'float-zero' => 0.0, 'integer' => 7, 'numeric-string' => '12', 'negative' => -4, 'invalid' => 'not-a-number'];
		$out['values'] = [];
		foreach ($types as $index => $type) {
			$table = 'metric_' . $index;
			$db->exec("CREATE TABLE `$table` (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, value $type NULL) ENGINE=InnoDB");
			$cases = $inputs;
			if (in_array($type, ['float', 'double', 'decimal(20,4)'], true)) $cases['fraction'] = '1.25';
			if ($type === 'bigint') $cases['maximum'] = '9223372036854775807';
			foreach ($cases as $label => $input) {
				$id = sql_save(['id' => '', 'value' => $input], $table, 'id', true, $db);
				if (!$id) throw new RuntimeException('Numeric save did not return an identity');
				$query = $db->prepare("SELECT CAST(value AS CHAR) FROM `$table` WHERE id=?");
				$query->execute([$id]);
				$out['values'][$type][$label] = $query->fetchColumn();
			}
		}
	} elseif ($mode === 'defaults') {
		$db->exec("CREATE TABLE defaults_probe (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, optional INT NULL DEFAULT 7, required_value INT NOT NULL DEFAULT 9, no_default INT NOT NULL, text_value VARCHAR(20) NULL DEFAULT 'stored') ENGINE=InnoDB");
		foreach (['empty' => '', 'null' => null, 'false' => false, 'zero' => 0] as $label => $value) {
			$id = sql_save(['id' => '', 'optional' => $value, 'required_value' => $value, 'no_default' => $value, 'text_value' => ''], 'defaults_probe', 'id', true, $db);
			$out['rows'][$label] = $db->query('SELECT optional,required_value,no_default,text_value FROM defaults_probe WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
		}
		$id = sql_save(['id' => null, 'no_default' => 3], 'defaults_probe', 'id', true, $db);
		$out['rows']['omitted'] = $db->query('SELECT optional,required_value,no_default,text_value FROM defaults_probe WHERE id=' . (int) $id)->fetch(PDO::FETCH_ASSOC);
		$out['identity'] = (int) $id > 0;
	} elseif (in_array($mode, ['updates', 'failure', 'refusal', 'negative-refusal'], true)) {
		foreach ([$default, $db] as $index => $connection) {
			$constraint = in_array($mode, ['refusal', 'negative-refusal'], true) ? ', CHECK (value IS NOT NULL AND value >= 0)' : '';
			$connection->exec("CREATE TABLE metric (id INT NOT NULL PRIMARY KEY, value INT NULL, label VARCHAR(20) NOT NULL DEFAULT ''$constraint) ENGINE=InnoDB");
			$connection->exec('INSERT INTO metric (id,value) VALUES (1,' . (41 + $index) . ')');
		}
		if ($mode === 'failure') {
			$out['result'] = sql_save(['id' => 1, 'value' => '', 'missing_column' => 2], 'metric', 'id', true, $db);
			$out['stored'] = $db->query('SELECT value FROM metric WHERE id=1')->fetchColumn();
			$out['messages'] = $messages;
		} elseif ($mode === 'refusal' || $mode === 'negative-refusal') {
			$db->beginTransaction();
			$db->exec("UPDATE metric SET label='owned' WHERE id=1");
			$out['result'] = sql_save(['id' => 1, 'value' => $mode === 'negative-refusal' ? -1 : ''], 'metric', 'id', true, $db);
			$out['caller_transaction'] = $db->inTransaction();
			$out['stored'] = $db->query('SELECT value,label FROM metric WHERE id=1')->fetch(PDO::FETCH_ASSOC);
		} else {
			$db->beginTransaction();
			$out['id'] = sql_save(['id' => 1, 'value' => ''], 'metric', 'id', true, $db);
			$out['cleared'] = $db->query('SELECT value FROM metric WHERE id=1')->fetchColumn();
			$out['caller_transaction'] = $db->inTransaction();
			$out['default_unchanged'] = $default->query('SELECT value FROM metric WHERE id=1')->fetchColumn();
			$db->rollBack();
			$out['restored'] = $db->query('SELECT value FROM metric WHERE id=1')->fetchColumn();
			sql_save(['id' => 1, 'label' => 'changed'], 'metric', 'id', true, $db);
			$out['omitted_retained'] = $db->query('SELECT value,label FROM metric WHERE id=1')->fetch(PDO::FETCH_ASSOC);
		}
	} else {
		$schema = file_get_contents($root . '/cacti.sql');
		if ($schema === false) throw new RuntimeException('Cannot read the shipped schema');
		foreach (['host' => 'avg_time', 'graph_templates_graph' => 'right_axis_format'] as $table => $column) {
			if (preg_match('/CREATE TABLE `?' . $table . '`?\s*\(.*?\) ENGINE=[^;]+;/s', $schema, $definition) !== 1) throw new RuntimeException('Shipped table definition missing');
			$db->exec($definition[0]);
			$id = sql_save(['id' => '', $column => ''], $table, 'id', true, $db);
			if (!$id) throw new RuntimeException('Shipped-schema save failed');
			$out['stored'][$table] = $db->query("SELECT `$column` FROM `$table` WHERE id=" . (int) $id)->fetchColumn();
		}
	}
} finally {
	if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
	foreach (array_reverse($owned) as $schema) $admin->exec("DROP DATABASE `$schema`");
}
echo json_encode($out, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), PHP_EOL;
