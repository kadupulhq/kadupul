<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

if (PHP_SAPI !== 'cli') {
	http_response_code(404);
	exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';

foreach (array('cacti_sizeof', 'cacti_count', 'clean_up_lines') as $function) {
	eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}
foreach (array('repair_automation') as $function) {
	eval(test_php_function_source(file_get_contents($root . '/install/functions.php'), $function));
}

function log_install_always(...$arguments) {}
function cacti_debug_backtrace(...$arguments) { return 'owned fixture'; }
function cacti_log(...$arguments) {
	$GLOBALS['automation_repair_logs'][] = $arguments;
}
function automation_repair_check($condition, $message) {
	$GLOBALS['automation_repair_checks']++;
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$scenario = $argv[1] ?? '';
$allowed = array('preserve-and-retry', 'empty', 'rule-read-error', 'query-read-error', 'graph-read-error', 'write-error', 'handled-prior-error');
if (!in_array($scenario, $allowed, true)) {
	fwrite(STDERR, "Unknown native automation scenario.\n");
	exit(2);
}

$database_hostname = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$database_port = getenv('BOOST_DB_PORT') ?: '3306';
$database_default = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
$user = getenv('BOOST_DB_USER') ?: 'root';
$password = getenv('BOOST_DB_PASSWORD') ?: '';
if (!in_array($database_hostname, array('127.0.0.1', 'localhost', 'db'), true)
	|| !in_array($database_default, array('cacti_boost_contract', 'kadupul_lts_automation'), true)) {
	throw new RuntimeException('Only the local disposable automation fixture is supported.');
}

$config = array('base_path' => $root, 'poller_id' => 1, 'is_web' => false);
$database_sessions = $database_details = $affected_rows = array();
$GLOBALS['automation_repair_logs'] = array();
$GLOBALS['automation_repair_checks'] = 0;
$created = array();
$report = array('scenario' => $scenario, 'php' => PHP_VERSION, 'passed' => false);
$pdo = null;

try {
	$pdo = db_connect_real($database_hostname, $user, $password, $database_default, 'mysql', $database_port, 0);
	if (!$pdo instanceof PDO) {
		throw new RuntimeException('The disposable database connection is unavailable.');
	}
	$report['database'] = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
	$report['sql_mode'] = $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
	$source = file_get_contents($root . '/cacti.sql');
	if (!is_string($source)) {
		throw new RuntimeException('The shipped schema is unavailable.');
	}
	foreach (array('automation_graph_rules', 'snmp_query', 'snmp_query_graph') as $table) {
		$query = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
		$query->execute(array($table));
		if ((int) $query->fetchColumn() !== 0) {
			throw new RuntimeException('The fixture refuses a table owned by another caller.');
		}
		if (!preg_match('/^CREATE TABLE `?' . $table . '`? \([\s\S]*?^\) ENGINE=[^;]+;/m', $source, $match)) {
			throw new RuntimeException('The shipped table definition is unavailable.');
		}
		if ($pdo->exec($match[0]) === false) {
			throw new RuntimeException('The shipped fixture table could not be created.');
		}
		$created[] = $table;
	}
	if (!preg_match('/^INSERT INTO `automation_graph_rules` VALUES [^\n]+;/m', $source, $match)) {
		throw new RuntimeException('The shipped automation seed is unavailable.');
	}
	$pdo->exec($match[0]);
	$pdo->exec("INSERT INTO snmp_query (id, hash, name) VALUES (37, '9343eab1f4d88b0e61ffc9d020f35414', 'Owned disk query')");
	$pdo->exec("INSERT INTO snmp_query_graph (id, hash, snmp_query_id, name) VALUES (41, '46c4ee688932cf6370459527eceb8ef3', 37, 'Owned disk graph')");
	$rows = static function () use ($pdo) {
		return $pdo->query('SELECT * FROM automation_graph_rules ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	};
	$before = $rows();
	$database_last_error = false;
	if ($scenario === 'empty') {
		$pdo->exec('DELETE FROM automation_graph_rules');
		repair_automation();
		automation_repair_check($rows() === array(), 'An empty rule inventory must stay empty.');
	} elseif ($scenario === 'preserve-and-retry' || $scenario === 'handled-prior-error') {
		if ($scenario === 'handled-prior-error') {
			db_fetch_cell_prepared('SELECT unavailable_fixture_column FROM snmp_query');
			automation_repair_check((bool) db_error(), 'The handled prior error must be a genuine native read failure.');
		}
		repair_automation();
		$after = $rows();
		$report['before'] = $before;
		$report['after'] = $after;
		automation_repair_check(array_slice($after, 0, 2) === array_slice($before, 0, 2), 'Unselected traffic references must not be replaced with zero identifiers.');
		automation_repair_check((int) $after[2]['snmp_query_id'] === 37 && (int) $after[2]['graph_type_id'] === 41, 'An admitted rule after missing references must still be repaired.');
		$pdo->exec("INSERT INTO snmp_query (id, hash, name) VALUES (57, 'd75e406fdeca4fcef45b8be3a9a63cbc', 'Owned traffic query')");
		$pdo->exec("INSERT INTO snmp_query_graph (id, hash, snmp_query_id, name) VALUES (59, 'ab93b588c29731ab15db601ca0bc9dec', 57, 'Owned traffic graph')");
		repair_automation();
		$repaired = $rows();
		foreach (array_slice($repaired, 0, 2) as $row) {
			automation_repair_check((int) $row['snmp_query_id'] === 57 && (int) $row['graph_type_id'] === 59, 'A later retry must publish newly available template references.');
		}
		repair_automation();
		automation_repair_check($rows() === $repaired, 'A completed repair must be idempotent.');
	} else {
		if ($scenario === 'rule-read-error') {
			$pdo->exec('DROP TABLE automation_graph_rules');
		} elseif ($scenario === 'query-read-error') {
			$pdo->exec('DROP TABLE snmp_query');
		} elseif ($scenario === 'graph-read-error') {
			$pdo->exec('DROP TABLE snmp_query_graph');
		} else {
			$pdo->exec("INSERT INTO snmp_query (id, hash, name) VALUES (57, 'd75e406fdeca4fcef45b8be3a9a63cbc', 'Owned traffic query')");
			$pdo->exec("INSERT INTO snmp_query_graph (id, hash, snmp_query_id, name) VALUES (59, 'ab93b588c29731ab15db601ca0bc9dec', 57, 'Owned traffic graph')");
			automation_repair_check($pdo->exec('ALTER TABLE automation_graph_rules ADD CONSTRAINT automation_repair_fixture_refusal CHECK (snmp_query_id NOT IN (37, 57))') !== false, 'The fixture must install its write refusal before invoking repair.');
		}
		$failed = false;
		try {
			repair_automation();
		} catch (RuntimeException $error) {
			$failed = true;
		}
		automation_repair_check($failed, 'A genuine native database failure must not become successful completion.');
		automation_repair_check((bool) db_error(), 'The failure must reach the native database error boundary.');
		if ($scenario !== 'rule-read-error') {
			automation_repair_check($rows() === $before, 'Failed reads and writes must not change any automation rule.');
		}
	}
	$report['passed'] = true;
} catch (Throwable $error) {
	$report['failure'] = get_class($error) . ': ' . $error->getMessage();
} finally {
	if ($pdo instanceof PDO) {
		foreach (array_reverse($created) as $table) {
			if ($pdo->exec('DROP TABLE IF EXISTS `' . $table . '`') === false) {
				$report['passed'] = false;
				$report['cleanup_failed'] = true;
			}
		}
	}
}
$report['checks'] = $GLOBALS['automation_repair_checks'];
echo json_encode($report, JSON_THROW_ON_ERROR) . PHP_EOL;
exit($report['passed'] ? 0 : 1);
