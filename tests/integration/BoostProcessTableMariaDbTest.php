<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$root = dirname(__DIR__, 2);

function boostMariaDbReset() {
	$host     = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
	$port     = getenv('BOOST_DB_PORT') ?: '3306';
	$database = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
	$user     = getenv('BOOST_DB_USER') ?: 'root';
	$password = getenv('BOOST_DB_PASSWORD') ?: '';
	$socket   = getenv('BOOST_DB_SOCKET');
	$dsn      = $socket ? "mysql:unix_socket=$socket;dbname=$database;charset=utf8mb4" :
		"mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";

	$GLOBALS['boost_mariadb_pdo'] = new PDO(
		$dsn,
		$user,
		$password,
		array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
	);
	$GLOBALS['boost_mariadb_cache'] = array('tables' => array(), 'columns' => array());
	$GLOBALS['boost_mariadb_logs']  = array();
	$GLOBALS['boost_retention_tables'] = array();
	$GLOBALS['boost_delete_calls'] = 0;
	$GLOBALS['boost_delete_fail_at'] = 0;
}

function boostMariaDbFetchCellPrepared($sql, $params = array()) {
	$statement = $GLOBALS['boost_mariadb_pdo']->prepare($sql);
	$statement->execute($params);

	return $statement->fetchColumn();
}

function boostMariaDbTableExists($table) {
	$cache =& $GLOBALS['boost_mariadb_cache']['tables'];

	if (!array_key_exists($table, $cache)) {
		$cache[$table] = (bool) boostMariaDbFetchCellPrepared('SELECT COUNT(*)
			FROM information_schema.TABLES
			WHERE TABLE_SCHEMA = SCHEMA()
			AND TABLE_NAME = ?', array($table));
	}

	return $cache[$table];
}

function boostMariaDbColumnExists($table, $column) {
	$cache =& $GLOBALS['boost_mariadb_cache']['columns'];
	$key = $table . '.' . $column;

	if (!array_key_exists($key, $cache)) {
		$cache[$key] = (bool) boostMariaDbFetchCellPrepared('SELECT COUNT(*)
			FROM information_schema.COLUMNS
			WHERE TABLE_SCHEMA = SCHEMA()
			AND TABLE_NAME = ?
			AND COLUMN_NAME = ?', array($table, $column));
	}

	return $cache[$key];
}

function boostMariaDbIndexExists($table, $index) {
	return (bool) boostMariaDbFetchCellPrepared('SELECT COUNT(*)
		FROM information_schema.STATISTICS
		WHERE TABLE_SCHEMA = SCHEMA()
		AND TABLE_NAME = ?
		AND INDEX_NAME = ?', array($table, $index));
}

function boostMariaDbExecute($sql) {
	try {
		$GLOBALS['boost_mariadb_pdo']->exec($sql);

		return true;
	} catch (PDOException $e) {
		return false;
	}
}

function boostMariaDbLog($message, $output = false, $facility = '') {
	$GLOBALS['boost_mariadb_logs'][] = $message;
}

function boostMariaDbLoadProductionFunctions($root) {
	if (function_exists('boostMariaDbEnsureProcessTable')) {
		return;
	}

	$source = file_get_contents($root . '/lib/boost.php');
	$start  = strpos($source, 'function boost_process_table_exists_uncached(');
	$end    = strpos($source, "\n/**\n * boost_array_orderby", $start);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	$functions = substr($source, $start, $end - $start);
	$functions = str_replace(array(
		'boost_process_table_exists_uncached',
		'boost_process_column_exists_uncached',
		'boost_ensure_process_table',
		'db_fetch_cell_prepared',
		'db_table_exists',
		'db_column_exists',
		'db_index_exists',
		'db_execute',
		'cacti_log',
	), array(
		'boostMariaDbProcessTableExistsUncached',
		'boostMariaDbProcessColumnExistsUncached',
		'boostMariaDbEnsureProcessTable',
		'boostMariaDbFetchCellPrepared',
		'boostMariaDbTableExists',
		'boostMariaDbColumnExists',
		'boostMariaDbIndexExists',
		'boostMariaDbExecute',
		'boostMariaDbLog',
	), $functions);

	eval($functions);
}

beforeEach(function () use ($root) {
	boostMariaDbLoadProductionFunctions($root);
	boostMariaDbReset();
	$GLOBALS['boost_mariadb_pdo']->exec('DROP TABLE IF EXISTS poller_output_boost_processes');
	$GLOBALS['boost_mariadb_pdo']->exec('DROP TABLE IF EXISTS poller_output_boost_local_data_ids');
});

afterEach(function () {
	$GLOBALS['boost_mariadb_pdo']->exec('DROP TABLE IF EXISTS poller_output_boost_processes');
	$GLOBALS['boost_mariadb_pdo']->exec('DROP TABLE IF EXISTS poller_output_boost_local_data_ids');
});

test('run-scoped Boost cursor table executes on MariaDB and advances monotonically', function () {
	$GLOBALS['boost_mariadb_pdo']->exec("CREATE TABLE poller_output_boost_local_data_ids (
		run_id char(32) NOT NULL,
		local_data_id int unsigned NOT NULL default 0,
		process_handler int unsigned NOT NULL default 0,
		cursor_time timestamp NULL default NULL,
		cursor_rrd_name varchar(19) NOT NULL default '',
		PRIMARY KEY (run_id, local_data_id),
		INDEX process_handler(run_id, process_handler)) ENGINE=MEMORY");
	$run_id = str_repeat('a', 32);
	$insert = $GLOBALS['boost_mariadb_pdo']->prepare('INSERT INTO poller_output_boost_local_data_ids
		(run_id, local_data_id, process_handler) VALUES (?, ?, ?)');
	$insert->execute(array($run_id, 42, 3));
	$update = $GLOBALS['boost_mariadb_pdo']->prepare('UPDATE poller_output_boost_local_data_ids
		SET cursor_time = ?, cursor_rrd_name = ?
		WHERE run_id = ? AND local_data_id = ? AND process_handler = ?');
	$update->execute(array('2026-09-05 12:00:00', 'traffic_out', $run_id, 42, 3));
	$row = $GLOBALS['boost_mariadb_pdo']->query('SELECT run_id, local_data_id, process_handler,
		cursor_time, cursor_rrd_name FROM poller_output_boost_local_data_ids')->fetch(PDO::FETCH_ASSOC);

	expect($row['run_id'])->toBe($run_id)
		->and((int) $row['local_data_id'])->toBe(42)
		->and((int) $row['process_handler'])->toBe(3)
		->and($row['cursor_time'])->toBe('2026-09-05 12:00:00')
		->and($row['cursor_rrd_name'])->toBe('traffic_out');
});

test('run-scoped cursor predicate pages archive rows in primary-key order', function () {
	$GLOBALS['boost_mariadb_pdo']->exec("CREATE TABLE poller_output_boost_local_data_ids (
		run_id char(32) NOT NULL,
		local_data_id int unsigned NOT NULL default 0,
		process_handler int unsigned NOT NULL default 0,
		cursor_time timestamp NULL default NULL,
		cursor_rrd_name varchar(19) NOT NULL default '',
		PRIMARY KEY (run_id, local_data_id),
		INDEX process_handler(run_id, process_handler)) ENGINE=MEMORY");
	$GLOBALS['boost_mariadb_pdo']->exec('CREATE TEMPORARY TABLE boost_archive (
		local_data_id int unsigned NOT NULL,
		rrd_name varchar(19) NOT NULL,
		time timestamp NOT NULL,
		output varchar(512) NOT NULL,
		PRIMARY KEY (local_data_id, time, rrd_name))');
	$GLOBALS['boost_mariadb_pdo']->exec('CREATE TEMPORARY TABLE data_local (
		id int unsigned NOT NULL PRIMARY KEY,
		data_template_id int unsigned NOT NULL)');
	$run_id = str_repeat('b', 32);
	$statement = $GLOBALS['boost_mariadb_pdo']->prepare('INSERT INTO poller_output_boost_local_data_ids
		(run_id, local_data_id, process_handler) VALUES (?, 42, 3)');
	$statement->execute(array($run_id));
	$GLOBALS['boost_mariadb_pdo']->exec('INSERT INTO data_local VALUES (42, 7)');
	$GLOBALS['boost_mariadb_pdo']->exec("INSERT INTO boost_archive VALUES
		(42, 'a', '2026-09-05 12:00:00', '1'),
		(42, 'b', '2026-09-05 12:00:00', '2'),
		(42, 'a', '2026-09-05 12:01:00', '3'),
		(42, 'b', '2026-09-05 12:01:00', '4')");
	$query = 'SELECT * FROM (
		SELECT boost_archive.local_data_id, dl.data_template_id,
			UNIX_TIMESTAMP(boost_archive.time) AS timestamp,
			boost_archive.time AS sample_time, boost_archive.rrd_name, boost_archive.output
		FROM boost_archive
		INNER JOIN poller_output_boost_local_data_ids AS bpt
		ON boost_archive.local_data_id = bpt.local_data_id
		INNER JOIN data_local AS dl ON boost_archive.local_data_id = dl.id
		WHERE bpt.run_id = ? AND bpt.process_handler = ?
		AND (bpt.cursor_time IS NULL OR boost_archive.time > bpt.cursor_time
			OR (boost_archive.time = bpt.cursor_time AND boost_archive.rrd_name > bpt.cursor_rrd_name))
		) AS page ORDER BY local_data_id, sample_time, rrd_name LIMIT 3';
	$statement = $GLOBALS['boost_mariadb_pdo']->prepare($query);
	$statement->execute(array($run_id, 3));
	$first_page = $statement->fetchAll(PDO::FETCH_ASSOC);

	expect($first_page)->toHaveCount(3)
		->and(array_column($first_page, 'rrd_name'))->toBe(array('a', 'b', 'a'));

	$statement = $GLOBALS['boost_mariadb_pdo']->prepare('UPDATE poller_output_boost_local_data_ids
		SET cursor_time = ?, cursor_rrd_name = ? WHERE run_id = ? AND local_data_id = 42');
	$statement->execute(array('2026-09-05 12:00:00', 'b', $run_id));
	$statement = $GLOBALS['boost_mariadb_pdo']->prepare($query);
	$statement->execute(array($run_id, 3));
	$second_page = $statement->fetchAll(PDO::FETCH_ASSOC);

	expect($second_page)->toHaveCount(2)
		->and(array_column($second_page, 'rrd_name'))->toBe(array('a', 'b'));
});

test('runtime repair executes valid process-table DDL on MariaDB', function () {
	$GLOBALS['boost_mariadb_pdo']->exec('CREATE TABLE poller_output_boost_processes (
		sock_int_value bigint unsigned NOT NULL AUTO_INCREMENT,
		status varchar(255) DEFAULT NULL,
		PRIMARY KEY (sock_int_value)) ENGINE=MEMORY');

	expect(boostMariaDbEnsureProcessTable(true))->toBeTrue()
		->and(boostMariaDbProcessColumnExistsUncached('run_id'))->toBeTrue()
		->and(boostMariaDbProcessColumnExistsUncached('child_id'))->toBeTrue()
		->and(boostMariaDbIndexExists('poller_output_boost_processes', 'run_child'))->toBeTrue()
		->and($GLOBALS['boost_mariadb_logs'])->toBe(array());
});

test('uncached recheck accepts a concurrent column repair after cached absence', function () {
	$GLOBALS['boost_mariadb_pdo']->exec('CREATE TABLE poller_output_boost_processes (
		sock_int_value bigint unsigned NOT NULL AUTO_INCREMENT,
		status varchar(255) DEFAULT NULL,
		PRIMARY KEY (sock_int_value)) ENGINE=MEMORY');

	expect(boostMariaDbTableExists('poller_output_boost_processes'))->toBeTrue()
		->and(boostMariaDbColumnExists('poller_output_boost_processes', 'run_id'))->toBeFalse();

	$GLOBALS['boost_mariadb_pdo']->exec("ALTER TABLE poller_output_boost_processes
		ADD run_id char(32) NOT NULL DEFAULT '' AFTER sock_int_value");

	expect(boostMariaDbEnsureProcessTable(true))->toBeTrue()
		->and(boostMariaDbProcessColumnExistsUncached('run_id'))->toBeTrue()
		->and(boostMariaDbProcessColumnExistsUncached('child_id'))->toBeTrue()
		->and(boostMariaDbIndexExists('poller_output_boost_processes', 'run_child'))->toBeTrue()
		->and($GLOBALS['boost_mariadb_logs'])->toBe(array());
});

test('runtime repair clears duplicate legacy rows before adding the run-child key', function () {
	$GLOBALS['boost_mariadb_pdo']->exec('CREATE TABLE poller_output_boost_processes (
		sock_int_value bigint unsigned NOT NULL AUTO_INCREMENT,
		run_id char(32) NOT NULL DEFAULT \'\',
		child_id int unsigned NOT NULL DEFAULT 0,
		status varchar(255) DEFAULT NULL,
		PRIMARY KEY (sock_int_value)) ENGINE=MEMORY');
	$GLOBALS['boost_mariadb_pdo']->exec("INSERT INTO poller_output_boost_processes
		(run_id, child_id, status) VALUES ('', 0, '1'), ('', 0, '2')");

	expect(boostMariaDbEnsureProcessTable(true))->toBeTrue()
		->and(boostMariaDbIndexExists('poller_output_boost_processes', 'run_child'))->toBeTrue()
		->and((int) boostMariaDbFetchCellPrepared('SELECT COUNT(*) FROM poller_output_boost_processes'))->toBe(0)
		->and($GLOBALS['boost_mariadb_logs'])->toBe(array());
});

function boostMariaDbDeletePrepared($sql, $params) {
	$sql = strtr($sql, $GLOBALS['boost_retention_tables'] ?? array());
	$GLOBALS['boost_delete_statement'] = array($sql, $params);
	if (++$GLOBALS['boost_delete_calls'] === $GLOBALS['boost_delete_fail_at']) {
		return false;
	}
	try {
		$statement = $GLOBALS['boost_mariadb_pdo']->prepare($sql);
		$statement->execute($params);
		$GLOBALS['boost_delete_affected'] = $statement->rowCount();
		return true;
	} catch (PDOException $error) {
		$GLOBALS['boost_delete_affected'] = 0;
		return false;
	}
}

function boostMariaDbDeleteAffected() {
	return $GLOBALS['boost_delete_affected'];
}

test('poller deletes only its selected sample keys when newer rows arrive before deletion', function () use ($root) {
	boostMariaDbLoadDeleteRows($root);
	$db = $GLOBALS['boost_mariadb_pdo'];
	$db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=MEMORY');
	try {
		$insert = $db->prepare('INSERT INTO poller_output VALUES (?,?,?,?)');
		$insert->execute(array(7, 'traffic_in', '2026-09-15 00:00:00', '10'));
		$selected = $db->query('SELECT local_data_id,rrd_name,time,output FROM poller_output')->fetchAll(PDO::FETCH_NUM);
		// Deterministic interleaving: these rows arrive after the drain's SELECT.
		$insert->execute(array(7, 'traffic_in', '2026-09-15 00:01:00', '11'));
		$insert->execute(array(7, 'traffic_out', '2026-09-15 00:00:00', '12'));
		expect(boostMariaDbDeleteOutputRows($selected))->toBe(1)
			->and((int) $db->query('SELECT count(*) FROM poller_output')->fetchColumn())->toBe(2)
			->and(boostMariaDbDeleteOutputRows($selected))->toBe(0)
			->and(boostMariaDbDeleteOutputRows(array()))->toBe(0);
		expect($db->query('SELECT output FROM poller_output ORDER BY output')->fetchAll(PDO::FETCH_COLUMN))->toBe(array('11', '12'));
        $insert->execute(array(7, 'traffic_in', '2026-09-15 00:00:00', '99'));
        expect(boostMariaDbDeleteOutputRows($selected))->toBe(0)
            ->and($db->query("SELECT output FROM poller_output WHERE output='99'")->fetchColumn())->toBe('99');
        expect(boostMariaDbDeleteOutputRows(array(array(7, 'traffic_in', '2026-09-15 00:00:00')), $failed))->toBe(0)
            ->and($failed)->toBeTrue();

	} finally {
		$db->exec('DROP TEMPORARY TABLE poller_output');
	}
});

function boostMariaDbLoadDeleteRows($root) {
	if (!function_exists('boostMariaDbDeleteOutputRows')) {
		preg_match('/^function poller_delete_output_rows\(.*?^}\n/ms', file_get_contents($root . '/lib/poller.php'), $match);
		expect($match)->not->toBeEmpty();
		eval(str_replace(array('poller_delete_output_rows(', 'db_execute_prepared(', 'db_affected_rows(', 'cacti_sizeof('), array('boostMariaDbDeleteOutputRows(', 'boostMariaDbDeletePrepared(', 'boostMariaDbDeleteAffected(', 'count('), $match[0]));
	}
}

test('poller acknowledgement preserves byte-distinct replacement values and uses the primary key', function ($observed, $replacement, $batch_size, $collation) use ($root) {
	boostMariaDbLoadDeleteRows($root);
	$db = $GLOBALS['boost_mariadb_pdo'];
	$db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=InnoDB COLLATE=' . $collation);
	try {
		$rows = $keys = array();
		for ($id = 1; $id <= 10000; $id++) {
			$rows[] = "($id,'value','2026-09-15 00:00:00'," . $db->quote($observed) . ')';
			if ($id <= $batch_size) { $keys[] = array($id, 'value', '2026-09-15 00:00:00', $observed); }
		}
		$db->exec('INSERT INTO poller_output VALUES ' . implode(',', $rows));
		$db->prepare('UPDATE poller_output SET output=? WHERE local_data_id=1')->execute(array($replacement));
		expect(boostMariaDbDeleteOutputRows($keys, $failed))->toBe($batch_size - 1)->and($failed)->toBeFalse();
		expect($db->query('SELECT output FROM poller_output WHERE local_data_id=1')->fetchColumn())->toBe($replacement);
		list($sql, $params) = $GLOBALS['boost_delete_statement'];
		$explain = $db->prepare('EXPLAIN FORMAT=TRADITIONAL ' . $sql);
		$explain->execute($params);
		$plan = $explain->fetch(PDO::FETCH_ASSOC);
		expect($plan['key'])->toBe('PRIMARY')->and($plan['type'])->toBe('range');
	} finally {
		$db->exec('DROP TEMPORARY TABLE poller_output');
	}
})->with(array(array('U', 'u'), array('42', '42 '), array('café', 'CAFÉ'), array('café', 'café ')))->with(array(2, 500))->with(array('utf8mb4_unicode_ci', 'latin1_swedish_ci'));


test('poller reports failed source deletion even after earlier chunks made progress', function ($fail_at) use ($root) {
	boostMariaDbLoadDeleteRows($root);
	$db = $GLOBALS['boost_mariadb_pdo'];
	$db->exec('CREATE TEMPORARY TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(32), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=MEMORY');
	try {
		$values = array();
		$keys = array();
		for ($id = 1; $id <= 501; $id++) {
			$values[] = "($id,'value','2026-09-15 00:00:00','10')";
			$keys[] = array($id, 'value', '2026-09-15 00:00:00', '10');
		}
		$db->exec('INSERT INTO poller_output VALUES ' . implode(',', $values));
		$GLOBALS['boost_delete_fail_at'] = $fail_at;
		$consumed = boostMariaDbDeleteOutputRows($keys, $failed);
		expect($failed)->toBeTrue()
			->and($consumed)->toBe($fail_at === 1 ? 0 : 500)
			->and((int) $db->query('SELECT count(*) FROM poller_output')->fetchColumn())->toBe(501 - $consumed);
	} finally {
		$db->exec('DROP TEMPORARY TABLE poller_output');
	}
})->with(array(1, 2));

function boostMariaDbRetentionRows($sql, $params) {
    $sql = strtr($sql, $GLOBALS['boost_retention_tables']);
    $statement = $GLOBALS['boost_mariadb_pdo']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

test('incomplete retention uses database time across PHP and database timezone differences', function ($zone) use ($root) {
    boostMariaDbLoadDeleteRows($root);
    if (!function_exists('boostMariaDbExpireIncomplete')) {
        preg_match('/^function poller_expire_incomplete_rows\(.*?^}\n/ms', file_get_contents($root . '/lib/poller.php'), $match);
        expect($match)->not->toBeEmpty();
        eval(str_replace(array('poller_expire_incomplete_rows(', 'db_fetch_assoc_prepared(', 'poller_delete_output_rows(', 'cacti_log('), array('boostMariaDbExpireIncomplete(', 'boostMariaDbRetentionRows(', 'boostMariaDbDeleteOutputRows(', 'boostMariaDbLog('), $match[0]));
    }
    $db = $GLOBALS['boost_mariadb_pdo'];
    $suffix = bin2hex(random_bytes(6));
    $tables = array('poller_output' => 'retention_output_' . $suffix, 'poller_item' => 'retention_item_' . $suffix);
    $GLOBALS['boost_retention_tables'] = $tables;
    $execute = function ($sql) use ($db, $tables) { return $db->exec(strtr($sql, $tables)); };
    $previousZone = date_default_timezone_get();
    date_default_timezone_set('Asia/Tokyo');
    try {
        $db->exec('SET time_zone=' . $db->quote($zone));
        $db->exec('SET timestamp=1700000000');
        $execute('CREATE TABLE poller_output (local_data_id INT, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(32), PRIMARY KEY(local_data_id,rrd_name,time)) ENGINE=InnoDB');
        $execute('CREATE TABLE poller_item (local_data_id INT, rrd_name VARCHAR(19), rrd_num INT)');
        $execute("INSERT INTO poller_item VALUES (1,'a',2),(1,'b',2),(2,'a',2),(3,'a',2),(4,'a',2)");
        $execute("INSERT INTO poller_output VALUES (1,'a',FROM_UNIXTIME(1699999100),'complete-a'),(1,'b',FROM_UNIXTIME(1699999100),'complete-b'),(2,'a',FROM_UNIXTIME(1699999100),'expired'),(3,'a',FROM_UNIXTIME(1699999700),'recent'),(4,'a',FROM_UNIXTIME(1699999400),'boundary')");
        expect(boostMariaDbExpireIncomplete(600, $failed))->toBe(1)->and($failed)->toBeFalse();
        expect($db->query('SELECT output FROM ' . $tables['poller_output'] . ' ORDER BY output')->fetchAll(PDO::FETCH_COLUMN))->toBe(array('boundary','complete-a','complete-b','recent'));
    } finally {
        date_default_timezone_set($previousZone);
        $execute('DROP TABLE IF EXISTS poller_output, poller_item');
        $GLOBALS['boost_retention_tables'] = array();
        $db->exec('SET timestamp=0');
    }
})->with(array('+00:00', '-08:00', '+05:30'));
