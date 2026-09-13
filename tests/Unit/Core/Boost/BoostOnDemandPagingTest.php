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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Viewing a graph runs boost_process_poller_output() for its data sources.
 * 1.2.31 wrote every staged row for the data source at that point.  A data
 * source with more rows than boost_rrd_update_max_records_per_select must
 * still be written in full, one bounded page at a time.
 */

$root = dirname(__DIR__, 4);

foreach (array('BOOST_TIMER_START' => 1, 'BOOST_TIMER_END' => 0, 'POLLER_VERBOSITY_MEDIUM' => 3, 'POLLER_VERBOSITY_HIGH' => 4) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

if (!function_exists('boost_error_handler')) {
	function boost_error_handler() {
		return false;
	}
}

function boostPaging_state() {
	return $GLOBALS['boost_paging'];
}

function boostPaging_read_config_option($name) {
	return isset($GLOBALS['boost_paging']['options'][$name]) ? $GLOBALS['boost_paging']['options'][$name] : '';
}

function boostPaging_db_fetch_assoc_prepared($sql, $params = array()) {
	$state =& $GLOBALS['boost_paging'];

	if (strpos($sql, 'UNIX_TIMESTAMP(po.time)') === false) {
		return array();
	}

	preg_match('/LIMIT\s+(\d+)/', $sql, $limit);

	$cutoff = $params[1];
	$cursor = isset($params[2]) ? $params[2] : null;
	$page   = array();

	foreach ($state['rows'] as $row) {
		if ($row['timestamp'] >= $cutoff || ($cursor !== null && $row['timestamp'] <= $cursor)) {
			continue;
		}

		$page[] = $row;

		if (count($page) == (int) $limit[1]) {
			break;
		}
	}

	$state['page_sizes'][] = count($page);

	return $page;
}

function boostPaging_db_execute_prepared($sql, $params = array(), $log = true) {
	$GLOBALS['boost_paging']['executed'][] = $sql;

	return true;
}

function boostPaging_boost_rrdtool_function_update($local_data_id, $rrd_path, $rrd_tmpl, &$values, &$pipe) {
	$state =& $GLOBALS['boost_paging'];
	$state['update_calls']++;

	foreach (preg_split('/\s+/', trim($values)) as $sample) {
		$parts = explode(':', $sample);
		$state['written'][array_shift($parts)] = $parts;
	}

	return $state['update_calls'] == $state['fail_update_call'] ? 'ERROR: test' : 'OK';
}

function boostPaging_rrd_init() {
	$GLOBALS['boost_paging']['pipes_opened']++;

	return fopen('php://memory', 'w');
}

function boostPaging_rrd_close($pipe) {
	$GLOBALS['boost_paging']['pipes_closed']++;
}

function boostPaging_boost_get_rrd_filename_and_template($local_data_id) {
	return array('rrd_path' => '/rra/7.rrd', 'rrd_template' => 'traffic_in:traffic_out');
}

function boostPaging_cacti_log($message) {
	$GLOBALS['boost_paging']['logs'][] = $message;
}

function boostPaging_boost_get_arch_table_names($table) { return array(); }
function boostPaging_db_fetch_cell($sql) { return ''; }
function boostPaging_db_execute($sql) { return true; }
function boostPaging_db_affected_rows() { return 0; }
function boostPaging_boost_timer($area, $action) {}
function boostPaging_cacti_system_zone_set() {}
function boostPaging_boost_get_unused_data_source_names($local_data_id) { return array(); }
function boostPaging_boost_get_input_field_names($local_data_id, $templated) { return array(); }
function boostPaging_get_rrdtool_version() { return '1.7.2'; }
function boostPaging_cacti_version_compare($a, $b, $operator) { return version_compare($a, $b, $operator); }
function boostPaging_cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function boostPaging_cacti_count($value) { return boostPaging_cacti_sizeof($value); }

function boostPagingLoad($root) {
	if (function_exists('boostPaging_boost_process_poller_output')) {
		return;
	}

	$source = file_get_contents($root . '/lib/boost.php');
	$names  = 'boost_process_poller_output|boost_limit_complete_timestamp_page|boost_rrdtool_function_update|boost_get_rrd_filename_and_template|boost_get_arch_table_names|boost_get_unused_data_source_names|boost_get_input_field_names|boost_timer|read_config_option|db_fetch_assoc_prepared|db_fetch_cell|db_execute_prepared|db_execute|db_affected_rows|rrd_init|rrd_close|cacti_log|cacti_system_zone_set|cacti_version_compare|get_rrdtool_version|cacti_sizeof|cacti_count';

	foreach (array('boost_limit_complete_timestamp_page', 'boost_process_poller_output') as $name) {
		$start = strpos($source, 'function ' . $name . '(');
		$end   = strpos($source, "\nfunction ", $start + 1);

		expect($start)->not->toBeFalse()
			->and($end)->not->toBeFalse();

		eval(preg_replace('/\b(' . $names . ')\(/', 'boostPaging_$1(', substr($source, $start, $end - $start)));
	}
}

/* two data source values per timestamp, in the order the query returns them */
function boostPagingReset($timestamps, array $options = array()) {
	$rows = array();

	for ($i = 1; $i <= $timestamps; $i++) {
		foreach (array('traffic_in', 'traffic_out') as $offset => $name) {
			$rows[] = array(
				'local_data_id'    => 7,
				'data_template_id' => 0,
				'timestamp'        => 1700000000 + $i * 300,
				'rrd_name'         => $name,
				'output'           => (string) ($i * 2 + $offset),
			);
		}
	}

	$GLOBALS['boost_paging'] = array(
		'rows'             => $rows,
		'options'          => $options + array('boost_rrd_update_string_length' => 2000),
		'page_sizes'       => array(),
		'executed'         => array(),
		'written'          => array(),
		'update_calls'     => 0,
		'fail_update_call' => 0,
		'pipes_opened'     => 0,
		'pipes_closed'     => 0,
		'logs'             => array(),
	);
}

function boostPagingDeletes() {
	return count(array_filter($GLOBALS['boost_paging']['executed'], function ($sql) {
		return strpos($sql, 'DELETE FROM poller_output_boost') !== false;
	}));
}

beforeEach(function () use ($root) {
	boostPagingLoad($root);

	$this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$this->library      = sys_get_temp_dir() . '/boost-paging-' . bin2hex(random_bytes(4));

	mkdir($this->library);
	file_put_contents($this->library . '/rrd.php', '<?php');

	$GLOBALS['config']     = array('library_path' => $this->library);
	$GLOBALS['get_memory'] = false;
});

afterEach(function () {
	$GLOBALS['config'] = $this->saved_config;

	unlink($this->library . '/rrd.php');
	rmdir($this->library);
});

test('a data source above the default 50000 row page is written in full on demand', function () {
	boostPagingReset(30001);

	$result = boostPaging_boost_process_poller_output(7);
	$state  = boostPaging_state();

	expect($result)->toBe(60002)
		->and(count($state['written']))->toBe(30001)
		->and($state['written'][1700000300])->toBe(array('2', '3'))
		->and($state['written'][1700000000 + 30001 * 300])->toBe(array('60002', '60003'))
		->and(count($state['page_sizes']))->toBeGreaterThan(1)
		->and(max($state['page_sizes']))->toBeLessThanOrEqual(50001)
		->and($state['pipes_opened'])->toBe(1)
		->and($state['pipes_closed'])->toBe(1)
		->and(boostPagingDeletes())->toBe(1);
});

test('pages end on a complete timestamp so no sample is split', function () {
	boostPagingReset(10, array('boost_rrd_update_max_records_per_select' => 3));

	expect(boostPaging_boost_process_poller_output(7))->toBe(20);

	$state = boostPaging_state();

	expect(count($state['written']))->toBe(10)
		->and(max($state['page_sizes']))->toBeLessThanOrEqual(4)
		->and(array_filter($state['written'], function ($values) {
			return count($values) != 2;
		}))->toBe(array());
});

test('a data source that fits in one page takes one query as in 1.2.31', function () {
	boostPagingReset(100);

	expect(boostPaging_boost_process_poller_output(7))->toBe(200)
		->and(boostPaging_state()['page_sizes'])->toBe(array(200))
		->and(boostPagingDeletes())->toBe(1);
});

test('a failed RRD update stops paging and keeps the staged rows', function () {
	boostPagingReset(10, array('boost_rrd_update_max_records_per_select' => 4));
	$GLOBALS['boost_paging']['fail_update_call'] = 1;

	expect(boostPaging_boost_process_poller_output(7))->toBe(-1)
		->and(count(boostPaging_state()['page_sizes']))->toBe(1)
		->and(boostPagingDeletes())->toBe(0);
});
