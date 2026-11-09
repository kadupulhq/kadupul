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

eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/utility.php'), 'poller_update_poller_cache_from_buffer'));
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 4) . '/lib/poller.php'), 'poller_update_poller_reindex_from_buffer'));

function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { $GLOBALS['pc_logs'][] = $message; }
function set_config_option($name, $value) {}
function raise_message(...$args) {}
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
	expect(pc_rows('poller_item'))->toContain(array(1, 1, 1))->toContain(array(2, 1, 1));
	expect($GLOBALS['pc_db']->query('SELECT COUNT(*) FROM poller_item WHERE present = 0')->fetchColumn())->toBe(0);
	expect(implode("\n", $GLOBALS['pc_logs']))->toContain('previous rows were kept');
})->with(array('first batch' => 1, 'later batch' => 2));

test('a failed mark leaves the poller cache untouched and stores nothing', function () {
	$items = array('(1, 1)');

	$GLOBALS['pc_fail'] = 'UPDATE poller_item SET present = 0';

	expect(poller_update_poller_cache_from_buffer(array(1, 2), $items, 1))->toBeFalse();
	expect($GLOBALS['pc_inserts'])->toBe(0);
	expect(pc_rows('poller_item'))->toBe(array(array(1, 1, 1), array(2, 1, 1)));
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
