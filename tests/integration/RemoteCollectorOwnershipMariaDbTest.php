<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace RemoteCollectorOwnership;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
foreach (array('poller_sync_lock', 'poller_sync_unlock', 'replicate_out') as $name) {
	eval('namespace ' . __NAMESPACE__ . '; use \\Throwable;' . test_php_function_source(file_get_contents(dirname(__DIR__, 2) . '/lib/poller.php'), $name));
}
eval('namespace ' . __NAMESPACE__ . '; use \\Throwable;' . test_php_function_source(file_get_contents(dirname(__DIR__, 2) . '/lib/boost.php'), 'boost_flush_output_batch'));

function connect() : \PDO {
	return new \PDO('mysql:host=' . (getenv('BOOST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('BOOST_DB_PORT') ?: '3306') . ';dbname=' . (getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract'), getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
}
function cacti_log(...$args) {}
function cacti_sizeof($value) { return is_countable($value) ? count($value) : 0; }
function poller_connect_to_remote($id) { return $GLOBALS['owner_connection']; }
function replicate_out_locked($id, $class, $conn) {
	$GLOBALS['owner_calls']++;
	if ($GLOBALS['owner_throw']) throw new \RuntimeException('Injected worker exception');
	return true;
}
function db_fetch_row($sql, $log, $conn) { return $conn->query($sql)->fetch(\PDO::FETCH_ASSOC); }
function db_execute($sql, $log, $conn) {
	if (isset($GLOBALS['owner_table'])) $sql = str_replace('poller_output_boost', $GLOBALS['owner_table'], $sql);
	try { $conn->exec($sql); return true; }
	catch (\PDOException $e) {
		// Match the legacy database boundary: logged writes may reconnect/retry.
		if ($log) { $GLOBALS['owner_retries']++; return connect()->exec($sql) !== false; }
		return false;
	}
}

test('the destination connection has one owner, including recursive calls and wrong-owner cleanup', function () {
	$a = connect(); $b = connect();
	$lock = poller_sync_lock($a, 'sync');
	expect($lock)->toBeString();
	try {
		expect(poller_sync_lock($a, 'sync'))->toBeFalse();
		expect(poller_sync_lock($b, 'sync'))->toBeFalse();
		expect(poller_sync_unlock($b, $lock))->toBeFalse();
		expect(poller_sync_lock($b, 'sync'))->toBeFalse();
	} finally { poller_sync_unlock($a, $lock); }
	$next = poller_sync_lock($b, 'sync');
	expect($next)->toBe($lock);
	expect(poller_sync_unlock($b, $next))->toBeTrue();
});

test('every replication class uses destination ownership and releases it after exceptions', function ($class, $throws) {
	$a = connect(); $b = connect();
	$GLOBALS['owner_connection'] = $b; $GLOBALS['owner_calls'] = 0; $GLOBALS['owner_throw'] = $throws;
	$lock = poller_sync_lock($a, 'sync');
	try { expect(replicate_out(2, $class))->toBeFalse()->and($GLOBALS['owner_calls'])->toBe(0); }
	finally { poller_sync_unlock($a, $lock); }
	expect(replicate_out(2, $class))->toBe(!$throws)->and($GLOBALS['owner_calls'])->toBe(1);
	$next = poller_sync_lock($a, 'sync');
	expect($next)->toBeString();
	poller_sync_unlock($a, $next);
})->with(array('all', 'data', 'settings', 'auth', 'plugins'))->with(array(false, true));

test('a disconnected recovery owner cannot reconnect and overwrite its successor', function () {
	$a = connect(); $b = connect();
	// The test database is disposable; the table is removed even after a failure.
	$table = 'recovery_owner_' . bin2hex(random_bytes(6));
	$GLOBALS['owner_table'] = $table;
	$b->exec('CREATE TABLE ' . $table . ' (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,time,rrd_name)) ENGINE=InnoDB');
	try {
		$lock = poller_sync_lock($a, 'recovery:2');
		expect($lock)->toBeString();
		// Populate the helper packet-limit cache before terminating this owned session.
		expect(boost_flush_output_batch(array("(1,'value','2026-11-01 00:00:00','initial')"), $a, true))->toBeTrue();
		$id = (int) $a->query('SELECT CONNECTION_ID()')->fetchColumn();
		$b->exec('KILL CONNECTION ' . $id);
		$next = poller_sync_lock($b, 'recovery:2');
		expect($next)->toBeString();
		expect(boost_flush_output_batch(array("(1,'value','2026-11-01 00:00:00','new')"), $b, true))->toBeTrue();
		$GLOBALS['owner_retries'] = 0;
		expect(boost_flush_output_batch(array("(1,'value','2026-11-01 00:00:00','old')"), $a, true))->toBeFalse();
		expect($GLOBALS['owner_retries'])->toBe(0);
		expect($b->query('SELECT output FROM ' . $table)->fetchColumn())->toBe('new');
		poller_sync_unlock($b, $next);
	} finally { $b->exec('DROP TABLE ' . $table); unset($GLOBALS['owner_table']); }
});
