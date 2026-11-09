<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Runs the discovery worker's own write helpers on the legacy database layer in
// the fail-fast mode poller_automation.php uses, against a real InnoDB deadlock.
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
require $root . '/lib/database.php';
require $root . '/lib/automation_runtime.php';
// eval() loads single functions from repository files, as the other native fixtures do.
foreach (array('cacti_sizeof', 'cacti_count', 'clean_up_lines', 'cacti_has_control_chars') as $function) {
	eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), $function));
}
$worker = file_get_contents($root . '/poller_automation.php');
eval(test_php_function_source($worker, 'updateDownDevice'));
function cacti_log(...$args) {}
function cacti_debug_backtrace(...$args) {}

$database_hostname = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$database_port = getenv('BOOST_DB_PORT') ?: '3306';
$database_default = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
if (!in_array($database_hostname, array('127.0.0.1', 'localhost'), true) || !in_array($database_default, array('cacti_boost_contract', 'cacti_automation_contract'), true)) {
	throw new RuntimeException('Use only the isolated automation database.');
}
$dsn = "mysql:host=$database_hostname;port=$database_port;dbname=$database_default;charset=utf8mb4";
$connect = static fn(): PDO => new PDO($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_FOUND_ROWS => true));
$pdo = $connect();
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$database_sessions = array("$database_hostname:$database_port:$database_default" => $pdo);
$config = array('base_path' => $root, 'poller_id' => 1, 'connection' => 'online');
$checks = 0;
function check(bool $condition, string $message): void {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}

$mode = $argv[1] ?? '';
if ($mode === '--holder') {
	// Lock the device's primary-key row, wait until the worker's UPDATE holds the
	// unique ip index entry and waits for that row, then read through the ip index
	// to close the cycle. Rows written to automation_processes make this transaction
	// heavier, so InnoDB chooses the worker as victim.
	$pdo->beginTransaction();
	$pdo->exec("INSERT INTO automation_processes (pid, network_id, task) SELECT 100000 + a.n * 20 + b.n + 1, 99, 'weight' FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16 UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19) b");
	$pdo->exec("UPDATE automation_devices SET sysName = 'holder' WHERE id = 2");
	print "ready\n";
	$waiting = $pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ? AND INFO LIKE 'UPDATE automation_devices%'");
	$until = microtime(true) + 10;
	do { usleep(50000); $waiting->execute(array((int)$argv[2])); } while ((int)$waiting->fetchColumn() === 0 && microtime(true) < $until);
	usleep(200000);
	$pdo->query("SELECT id FROM automation_devices WHERE ip = '192.0.2.2' FOR UPDATE")->fetchAll();
	$pdo->commit();
	exit(0);
}

$created = array();
try {
	$schema = file_get_contents($root . '/cacti.sql');
	foreach (array('automation_devices', 'automation_processes') as $table) {
		if (!preg_match('/CREATE TABLE `' . $table . '` \(.*?;/s', $schema, $match)) { throw new RuntimeException('Missing production schema'); }
		$pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
		$pdo->exec($match[0]);
		$created[] = $table;
	}
	$database_fail_fast = true;
	$pdo->exec("INSERT INTO automation_devices (id, network_id, ip, up) VALUES (1, 1, '192.0.2.1', 1), (2, 1, '192.0.2.2', 1)");
	$rollbacks = static fn(): int => (int)$pdo->query("SHOW SESSION STATUS LIKE 'Handler_rollback'")->fetch(PDO::FETCH_NUM)[1];
	$before = $rollbacks();
	$holder = proc_open(array(PHP_BINARY, __FILE__, '--holder', (string)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn()), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	check(is_resource($holder), 'deadlock holder started');
	fclose($pipes[0]);
	check(trim((string)fgets($pipes[1])) === 'ready', 'deadlock holder owns the device row');
	$outcome = null;
	try { updateDownDevice(1, '192.0.2.2'); } catch (Throwable $error) { $outcome = $error; }
	stream_get_contents($pipes[1]); fclose($pipes[1]); $holderError = stream_get_contents($pipes[2]); fclose($pipes[2]);
	check(proc_close($holder) === 0, 'deadlock holder committed: ' . $holderError);
	check($rollbacks() > $before, 'the server rolled back the deadlock victim');
	check($outcome === null, 'the worker retries its deadlocked write: ' . ($outcome ? $outcome->getMessage() : ''));
	$rows = $pdo->query('SELECT id, up, sysName FROM automation_devices ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
	check((int)$rows[1]['up'] === 0 && $rows[1]['sysName'] === 'holder', 'the retried write applies after the survivor commits');
	check((int)$rows[0]['up'] === 1, 'the retried write changes only its device');
	// The worker keeps the fail-fast policy, and none of its own writes bypass the retry.
	check(str_contains($worker, '$database_fail_fast = true;'), 'discovery workers run in fail-fast mode');
	foreach (array('discoverDevices', 'updateDownDevice') as $function) {
		$source = test_php_function_source($worker, $function);
		check(!preg_match('/\bdb_execute(_prepared)?\s*\(/', $source), $function . ' writes through automation_execute()');
	}
	print json_encode(array('checks' => $checks, 'php' => PHP_VERSION, 'database' => $pdo->query('SELECT VERSION()')->fetchColumn())) . "\n";
} finally {
	foreach (array_reverse($created) as $table) {
		$pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
	}
}
