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
 * When a data source's recorded path is missing, the migration loop in
 * cli/structure_rra_paths.php looks for the file under the flat legacy name
 * or the other structured layout. 1.2.31 pointed the database at the new
 * path as soon as that search finished, before the move, because nothing
 * between the search and rename() could refuse it. This branch added
 * structure_rra_is_safe_source() and structure_rra_is_safe_dest() after that
 * point, so a refused move left the database naming a file that was never
 * put there.
 *
 * The loop and the functions it calls are pulled out of the script and run
 * in a child PHP against a real temp directory, with only the database calls
 * stubbed, so each scenario exercises the shipped ordering rather than a copy
 * of it. A child keeps the script's global functions out of the Pest process,
 * where other files declare the same names.
 */

/**
 * Run the migration loop for one data source and report what it wrote to
 * the database and printed.
 *
 * @param string $base    Canonical rra directory the scenario was built in.
 * @param string $pattern The extended_paths_type setting.
 * @param array  $info    One row as the loop's SELECT returns it.
 *
 * @return array{writes: array<int, array{sql: string, params: array}>, printed: string}
 */
function structure_rra_loop_scenario($base, $pattern, $info) {
	$root   = dirname(__DIR__, 4);
	$source = file_get_contents($root . '/cli/structure_rra_paths.php');

	expect(preg_match('/^foreach \(\$data_sources as \$info\) \{\n.*?^}\n/ms', $source, $loop))
		->toBe(1, 'the migration loop is no longer where this test expects it');

	$functions = 'require_once ' . var_export($root . '/tests/Helpers/SpikekillPathFunctions.php', true) . ';';

	foreach (array('struct_debug', 'update_database', 'structure_rra_is_safe_source', 'structure_rra_is_safe_dest', 'structure_rra_prepare_dest_dir', 'sp_recursive_chown', 'sp_recursive_chgrp') as $function) {
		expect(preg_match('/^function ' . $function . '\(.*?^}\n/ms', $source, $match))
			->toBe(1, "$function() is no longer where this test expects it");

		$functions .= $match[0];
	}

	$code = '$writes = array(); $debug = false; $rollbacks = 0;'
		. '$fail_query = ' . var_export($info['fail_query'] ?? '', true) . ';'
		. 'function db_begin_transaction() { return true; }'
		. 'function db_commit_transaction() { return true; }'
		. 'function db_rollback_transaction() { global $rollbacks; $rollbacks++; return true; }'
		. 'function db_fetch_cell($sql) { return 1; }'
		. 'function db_fetch_cell_prepared($sql, $params = array()) { return "traffic_in"; }'
		. 'function db_execute_prepared($sql, $params = array()) { global $writes, $fail_query; $writes[] = array("sql" => preg_replace("/\s+/", " ", trim($sql)), "params" => $params); return $fail_query === "" || strpos($sql, $fail_query) === false; }'
		. 'function clean_up_file_name($string) { return $string; }'
		. 'eval(' . var_export($functions, true) . ');'
		. '$config = array("cacti_server_os" => "unix");'
		. '$base_rra_path = ' . var_export($base, true) . ';'
		. '$pattern = ' . var_export($pattern, true) . ';'
		. '$owner_id = fileowner($base_rra_path); $group_id = filegroup($base_rra_path);'
		. '$data_sources = array(' . var_export($info, true) . ');'
		. '$total_count = 1; $done_count = 0; $warn_count = 0; $skip_count = 0; $started = false; $database_failure = false;'
		. 'ob_start();'
		. 'eval(' . var_export($loop[0], true) . ');'
		. '$printed = ob_get_clean();'
		. 'echo json_encode(array("writes" => $writes, "printed" => $printed, "database_failure" => $database_failure, "rollbacks" => $rollbacks, "warn_count" => $warn_count));';

	$pipes   = array();
	$process = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);

	expect($process)->not->toBeFalse();

	$stdout = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);

	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0, $stdout . $error);

	$out = json_decode($stdout, true);

	expect($out)->toBeArray($stdout . $error);

	return $out;
}

function structure_rra_order_test_rrmdir($path) {
	if (is_link($path) || !is_dir($path)) {
		unlink($path);

		return;
	}

	foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
		structure_rra_order_test_rrmdir($path . '/' . $entry);
	}

	rmdir($path);
}

beforeEach(function () {
	$this->base = sys_get_temp_dir() . '/structure_rra_order_test_' . uniqid();
	mkdir($this->base, 0700, true);
	$this->base = realpath($this->base);

	/* a 'device' layout row whose recorded path is gone, so the loop falls
	   back to searching for the flat legacy name router_42.rrd */
	$this->info = array(
		'local_data_id'        => 42,
		'hash_id'              => 7,
		'host_id'              => 7,
		'snmp_query_id'        => 0,
		'description'          => 'router',
		'data_source_path'     => '<path_rra>/legacy/42.rrd',
		'rrd_path'             => $this->base . '/legacy/42.rrd',
		'new_data_source_path' => '<path_rra>/7/42.rrd',
		'new_rrd_path'         => $this->base . '/7/42.rrd',
	);

	$this->legacy = $this->base . '/router_42.rrd';
});

afterEach(function () {
	structure_rra_order_test_rrmdir($this->base);
});

test('a fallback file refused as a symlink leaves the database unchanged', function () {
	file_put_contents($this->base . '/target.rrd', 'rrd');
	symlink($this->base . '/target.rrd', $this->legacy);

	$out = structure_rra_loop_scenario($this->base, 'device', $this->info);

	expect($out['printed'])->toContain('Refusing to move Source Path')
		->and($out['writes'])->toBe(array())
		->and(is_link($this->legacy))->toBeTrue()
		->and(file_exists($this->info['new_rrd_path']))->toBeFalse();
});

test('a fallback file whose destination is refused leaves the database unchanged', function () {
	file_put_contents($this->legacy, 'rrd');
	mkdir($this->info['new_rrd_path'], 0700, true);

	$out = structure_rra_loop_scenario($this->base, 'device', $this->info);

	expect($out['printed'])->toContain('Refusing to move to Destination Path')
		->and($out['writes'])->toBe(array())
		->and(is_file($this->legacy))->toBeTrue();
});

test('a fallback file that is moved updates the database to the new path', function () {
	file_put_contents($this->legacy, 'rrd');

	$out = structure_rra_loop_scenario($this->base, 'device', $this->info);

	expect($out['printed'])->toBe('')
		->and(file_exists($this->legacy))->toBeFalse()
		->and(file_get_contents($this->info['new_rrd_path']))->toBe('rrd')
		->and($out['writes'])->toContain(array('sql' => 'UPDATE poller_item SET rrd_path = ? WHERE local_data_id = ?', 'params' => array($this->info['new_rrd_path'], 42)))
		->and($out['writes'])->toContain(array('sql' => 'UPDATE data_template_data SET data_source_path = ? WHERE local_data_id = ?', 'params' => array('<path_rra>/7/42.rrd', 42)));
});

test('a failed database path update rolls back and prevents layout activation', function ($failedQuery) {
	file_put_contents($this->legacy, 'rrd');
	$info = array_merge($this->info, array('fail_query' => $failedQuery));

	$out = structure_rra_loop_scenario($this->base, 'device', $info);

	expect($out['database_failure'])->toBeTrue()
		->and($out['rollbacks'])->toBe(1)
		->and(file_exists($this->info['new_rrd_path']))->toBeTrue();
})->with(array('UPDATE poller_item', 'UPDATE data_template_data'));

test('data query layout always includes the query ID in the target path', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/structure_rra_paths.php');

	expect($source)->toContain("$" . "info['host_id'] . '/' . $" . "info['snmp_query_id'] . '/' . $" . "local_data_id")
		->and($source)->toContain("$" . "info['hash_id'] . '/' . $" . "info['host_id'] . '/' . $" . "info['snmp_query_id'] . '/' . $" . "local_data_id");
});

test('a data source with no file anywhere still has the database pointed at the new path, as in 1.2.31', function () {
	$out = structure_rra_loop_scenario($this->base, 'device', $this->info);

	expect($out['printed'])->toContain('Does not exist, Skipping')
		->and($out['writes'])->toContain(array('sql' => 'UPDATE poller_item SET rrd_path = ? WHERE local_data_id = ?', 'params' => array($this->info['new_rrd_path'], 42)))
		->and($out['writes'])->toContain(array('sql' => 'UPDATE data_template_data SET data_source_path = ? WHERE local_data_id = ?', 'params' => array('<path_rra>/7/42.rrd', 42)));
});

test('a fallback file already at the new path updates the database without moving it, as in 1.2.31', function () {
	/* in the hash_device layout the second fallback name is the new path
	   itself, so only the database row is stale */
	$info = array_merge($this->info, array(
		'new_data_source_path' => '<path_rra>/7/7/42.rrd',
		'new_rrd_path'         => $this->base . '/7/7/42.rrd',
	));

	mkdir($this->base . '/7/7', 0700, true);
	file_put_contents($info['new_rrd_path'], 'rrd');

	$out = structure_rra_loop_scenario($this->base, 'hash_device', $info);

	expect($out['printed'])->toBe('')
		->and(file_get_contents($info['new_rrd_path']))->toBe('rrd')
		->and($out['writes'])->toContain(array('sql' => 'UPDATE poller_item SET rrd_path = ? WHERE local_data_id = ?', 'params' => array($info['new_rrd_path'], 42)))
		->and($out['writes'])->toContain(array('sql' => 'UPDATE data_template_data SET data_source_path = ? WHERE local_data_id = ?', 'params' => array('<path_rra>/7/7/42.rrd', 42)));
});
