<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * A poller cache rebuild marks the current rows with present = 0, stores the
 * replacements and deletes whatever is still marked.  When storing the
 * replacements fails, the previous rows must stay in place.
 */

namespace PollerCacheRebuildFailure;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/* The shipped function replaces the cache inside db_atomic(). database_atomic.php
 * needs a MySQL driver, so the transaction boundary is replaced below by one that
 * keeps its contract (begin, run, commit on true, roll back otherwise) on SQLite;
 * SubsystemFailureNativeTest drives the real boundary against MariaDB/MySQL. */
(function (): void {
	$source  = \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/utility.php'), 'poller_update_poller_cache_from_buffer');
	$require = "\trequire_once __DIR__ . '/database_atomic.php';\n";
	if (substr_count($source, $require) !== 1) {
		throw new \RuntimeException('poller_update_poller_cache_from_buffer no longer loads database_atomic.php as expected');
	}
	eval('namespace ' . __NAMESPACE__ . '; use PDO;' . str_replace($require, '', $source));
})();
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'poller_update_poller_reindex_from_buffer'));

if (!defined('MESSAGE_LEVEL_WARN')) {
	define('MESSAGE_LEVEL_WARN', 2);
}

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['pc_logs'][] = $message; }
function set_config_option($name, $value) {}
function raise_message(...$args) { $GLOBALS['pc_messages'][] = $args[0]; }
function remote_poller_up($poller_id) { return $GLOBALS['pc_remote_up']; }
function poller_push_to_remote_db_connect($poller_id, $retry) { return $GLOBALS['pc_remote']; }
function __($text, ...$args) { return $text; }
function poller_push_reindex_only_data_to_main($host_id, $data_query_id) { $GLOBALS['pc_pushed']++; return $GLOBALS['pc_push_ok']; }

function pc_fails($sql) {
	return $GLOBALS['pc_fail'] !== '' && strpos($sql, $GLOBALS['pc_fail']) === 0 && ++$GLOBALS['pc_seen'] >= $GLOBALS['pc_fail_at'];
}

function db_execute_prepared($sql, $params = array(), $log = true, $conn = false) {
	if (pc_fails(trim(preg_replace('/\s+/', ' ', $sql)))) {
		return false;
	}

	$GLOBALS['pc_db']->prepare($sql)->execute($params);

	return true;
}

// Stands in for INSERT ... ON DUPLICATE KEY UPDATE, which SQLite cannot parse.
function db_execute($sql, $log = true, $conn = false) {
	$GLOBALS['pc_inserts']++;

	if (pc_fails('INSERT')) {
		return false;
	}

	$table = strpos($sql, 'INTO poller_reindex') !== false ? 'poller_reindex' : 'poller_item';
	preg_match_all('/\((\d+), (\d+)\)/', $sql, $matches, PREG_SET_ORDER);

	foreach ($matches as $match) {
		$GLOBALS['pc_db']->exec($table === 'poller_item'
			? "INSERT OR REPLACE INTO poller_item VALUES ({$match[1]}, 1, 1)"
			: "INSERT OR REPLACE INTO poller_reindex VALUES ({$match[1]}, {$match[2]}, 1)");
	}

	return true;
}

function db_atomic(array $tables, callable $operation, $connection = false): bool {
	$db = $connection instanceof \PDO ? $connection : $GLOBALS['pc_db'];
	$db->beginTransaction();
	try {
		$ok = $operation($db) === true;
	} catch (\Throwable $error) {
		$ok = false;
	}
	$ok ? $db->commit() : $db->rollBack();

	return $ok;
}

// Stands in for INSERT ... ON DUPLICATE KEY UPDATE, which SQLite cannot parse.
function db_atomic_execute(\PDO $connection, string $sql, array $parameters = array()): bool {
	$sql = trim(preg_replace('/\s+/', ' ', $sql));
	if (pc_fails($sql)) {
		return false;
	}
	if (strpos($sql, 'SELECT id FROM data_local') === 0) {
		return true;
	}
	if (strpos($sql, 'INSERT INTO poller_item') === 0) {
		$GLOBALS['pc_inserts']++;
		preg_match_all('/\((\d+), (\d+)\)/', $sql, $matches, PREG_SET_ORDER);
		foreach ($matches as $match) {
			$connection->exec("INSERT OR REPLACE INTO poller_item VALUES ({$match[1]}, 1, 1)");
		}

		return true;
	}

	return $connection->prepare($sql)->execute($parameters);
}

beforeEach(function () {
	$db = new \PDO('sqlite::memory:');
	$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
	$db->exec('CREATE TABLE poller_item(local_data_id INTEGER PRIMARY KEY, poller_id INTEGER, present INTEGER)');
	$db->exec('CREATE TABLE poller_reindex(host_id INTEGER PRIMARY KEY, data_query_id INTEGER, present INTEGER)');
	$db->exec('INSERT INTO poller_item VALUES (1, 1, 1), (2, 1, 1)');
	$db->exec('INSERT INTO poller_reindex VALUES (1, 7, 1), (2, 7, 1)');
	$GLOBALS['pc_db']      = $db;
	$GLOBALS['pc_logs']    = array();
	$GLOBALS['pc_fail']    = '';
	$GLOBALS['pc_fail_at'] = 1;
	$GLOBALS['pc_seen']    = 0;
	$GLOBALS['pc_inserts'] = 0;
	$GLOBALS['pc_pushed']  = 0;
	$GLOBALS['pc_messages'] = array();
	$GLOBALS['pc_remote_up'] = true;
	$GLOBALS['pc_remote']   = false;
	$GLOBALS['pc_push_ok'] = true;
});

function pc_rows($table) {
	return $GLOBALS['pc_db']->query("SELECT * FROM $table ORDER BY 1")->fetchAll(\PDO::FETCH_NUM);
}

test('a successful rebuild replaces the current rows and removes stale ones', function () {
	$items = array('(1, 1)', '(3, 1)');

	expect(poller_update_poller_cache_from_buffer(array(1, 2, 3), $items, 1))->toBeTrue();
	expect(pc_rows('poller_item'))->toBe(array(array(1, 1, 1), array(3, 1, 1)));
});

test('a failed replacement insert keeps the previous poller cache rows', function ($failing_batch) {
	// Three records of about 130 KB: the first flush carries two of them, the final flush the third.
	$pad   = '/*' . str_repeat('x', 130000) . '*/';
	$items = array("(3, 1) $pad", "(4, 1) $pad", "(5, 1) $pad");

	$GLOBALS['pc_fail']    = 'INSERT';
	$GLOBALS['pc_fail_at'] = $failing_batch;

	expect(poller_update_poller_cache_from_buffer(array(1, 2), $items, 1))->toBeFalse();
	// The whole replacement rolls back: no new row and no row left marked stale.
	expect(pc_rows('poller_item'))->toBe(array(array(1, 1, 1), array(2, 1, 1)));
	expect($GLOBALS['pc_inserts'])->toBe($failing_batch - 1);
	expect(implode("\n", $GLOBALS['pc_logs']))->toContain('previous cache retained');
})->with(array('first batch' => 1, 'later batch' => 2));

test('a failed mark leaves the poller cache untouched and stores nothing', function () {
	$items = array('(1, 1)');

	$GLOBALS['pc_fail'] = 'UPDATE poller_item SET present=0';

	expect(poller_update_poller_cache_from_buffer(array(1, 2), $items, 1))->toBeFalse();
	expect($GLOBALS['pc_inserts'])->toBe(0);
	expect(pc_rows('poller_item'))->toBe(array(array(1, 1, 1), array(2, 1, 1)));
});

test('a remote collector that cannot be updated keeps its rows, is logged and reports failure', function () {
	$items = array('(1, 1)', '(3, 1)');
	$GLOBALS['pc_db']->exec('UPDATE poller_item SET poller_id = 2');
	$remote = new \PDO('sqlite::memory:');
	$remote->exec('CREATE TABLE poller_item(local_data_id INTEGER PRIMARY KEY, poller_id INTEGER, present INTEGER)');
	$remote->exec('INSERT INTO poller_item VALUES (1, 2, 1), (2, 2, 1)');

	$GLOBALS['pc_remote_up'] = false;
	expect(poller_update_poller_cache_from_buffer(array(1, 2, 3), $items, 2))->toBeFalse();
	expect(implode("\n", $GLOBALS['pc_logs']))->toContain('remote collector 2 failed or the collector is unreachable');
	expect($GLOBALS['pc_messages'])->toBe(array('poller_down_2'));

	$GLOBALS['pc_remote_up'] = true;
	$GLOBALS['pc_remote']    = $remote;
	$GLOBALS['pc_fail']      = 'INSERT';
	$GLOBALS['pc_fail_at']   = 2;
	$GLOBALS['pc_seen']      = 0;
	expect(poller_update_poller_cache_from_buffer(array(1, 2, 3), $items, 2))->toBeFalse();
	expect($remote->query('SELECT * FROM poller_item ORDER BY 1')->fetchAll(\PDO::FETCH_NUM))->toBe(array(array(1, 2, 1), array(2, 2, 1)));
});

test('a successful reindex rebuild removes stale rows and pushes to main', function () {
	$stack = array('(1, 7)');

	expect(poller_update_poller_reindex_from_buffer(1, 7, $stack))->toBeTrue();
	expect($GLOBALS['pc_pushed'])->toBe(1);
});

test('a failed reindex insert keeps the previous rows and does not push to main', function () {
	$stack = array('(1, 7)');

	$GLOBALS['pc_fail'] = 'INSERT';

	expect(poller_update_poller_reindex_from_buffer(1, 7, $stack))->toBeFalse();
	expect(pc_rows('poller_reindex'))->toBe(array(array(1, 7, 1), array(2, 7, 1)));
	expect($GLOBALS['pc_pushed'])->toBe(0);
});


test('a failed reindex cleanup is retained for retry and not published', function () {
    $stack = array('(1, 7)');
    $GLOBALS['pc_fail'] = 'DELETE FROM poller_reindex';
    expect(poller_update_poller_reindex_from_buffer(1, 7, $stack))->toBeFalse();
    expect($GLOBALS['pc_pushed'])->toBe(0);
});

test('a failed reindex publication is not reported as success', function () {
    $stack = array('(1, 7)');
    $GLOBALS['pc_push_ok'] = false;
    expect(poller_update_poller_reindex_from_buffer(1, 7, $stack))->toBeFalse();
    expect($GLOBALS['pc_pushed'])->toBe(1);
});
