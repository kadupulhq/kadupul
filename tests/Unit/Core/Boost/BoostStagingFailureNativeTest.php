<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Executable cover for the staging failure path. The companion test asserts
 * the shape of the source; this one runs boost_process_poller_output() with
 * each staging statement forced to fail and checks what actually happens to
 * the rows, because a control-flow regression can keep the source shape and
 * still lose samples.
 *
 * The defect: the archive cleanup is DELETE FROM <archive> WHERE
 * local_data_id = ?, scoped by data source rather than by what was read. The
 * three staging copies were issued with their results discarded, so a failed
 * copy left the rows unread and the delete then destroyed them.
 */

namespace BoostStagingFailureNative;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

// test-only eval of source read from this repository, not external input
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(
	file_get_contents(dirname(__DIR__, 4) . '/lib/boost.php'),
	'boost_process_poller_output'
));

const SQL_NO_CACHE = '';
const BOOST_TIMER_START = 1;
const BOOST_TIMER_END = 2;
const POLLER_VERBOSITY_LOW = 1;
const POLLER_VERBOSITY_MEDIUM = 2;
const POLLER_VERBOSITY_HIGH = 3;
const POLLER_VERBOSITY_DEBUG = 5;

/*
 * The function include_once's the RRD library from $config['library_path'].
 * $GLOBALS['config'] is shared by every test in the process, and the Boost
 * suite runs them together, so another file overwrites it with its own
 * directory and then removes that directory. Own the value at call time rather
 * than at load time, and keep the stub for the life of the process.
 */
$GLOBALS['stage_library'] = sys_get_temp_dir() . '/boost-staging-' . getmypid() . '-' . mt_rand();
mkdir($GLOBALS['stage_library'], 0700, true);
file_put_contents($GLOBALS['stage_library'] . '/rrd.php', '<?php');

register_shutdown_function(static function () {
	@unlink($GLOBALS['stage_library'] . '/rrd.php');
	@rmdir($GLOBALS['stage_library']);
});

function set_error_handler($handler) {}
function cacti_system_zone_set() {}
function boost_error_handler(...$args) { return true; }
function restore_error_handler() {}
function get_rrdtool_version() { return '1.7.2'; }
function cacti_version_compare($a, $b, $op) { return version_compare($a, $b, $op); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
function boost_timer(...$args) {}
function boost_debug(...$args) {}
function cacti_log($message, ...$args) { $GLOBALS['stage_logs'][] = $message; }
function boost_get_arch_table_names($table) { return array('poller_output_boost_arch_1'); }
function boost_get_unused_data_source_names($id) { return array(); }
function boost_get_rrd_filename_and_template($id) { return array('rrd_template' => 'value', 'rrd_path' => "/rra/$id.rrd"); }
function boost_rrdtool_pipe_creates(...$args) {}
function rrd_init(...$args) { return fopen('php://temp', 'r+'); }
function rrd_close($pipe) { if (is_resource($pipe)) { fclose($pipe); } }
function db_affected_rows(...$args) { return 1; }
function debounce_run_notification(...$args) {}
function is_hexadecimal($value) { return false; }
function boost_get_input_field_names($id) { return array('value'); }

/** Pass the page through unchanged; its own trimming is covered elsewhere. */
function boost_limit_complete_timestamp_page($results, $last_page) { return $results; }

function read_config_option($name) {
	if ($name === 'boost_rrd_update_string_length') {
		return $GLOBALS['stage_buffer'];
	}

	return '';
}

function boost_rrdtool_function_update($id, $path, $template, $values, $pipe) {
	$GLOBALS['stage_updates'][] = $values;

	return 'OK';
}

/**
 * Record every statement, and fail whichever staging step the case names.
 * Matching is done on the whitespace-normalised text, because the statements
 * are written across several lines in the source.
 */
function record($sql) {
	$flat = preg_replace('/\s+/', ' ', trim($sql));

	$GLOBALS['stage_sql'][] = $flat;

	$fail = $GLOBALS['stage_fail'];

	if ($fail === 'create' && strpos($flat, 'CREATE TEMPORARY TABLE') !== false) {
		return false;
	}

	if (strpos($flat, 'INSERT IGNORE INTO') === 0) {
		$archive = strpos($flat, '_arch_') !== false;

		if (($fail === 'archive' && $archive) || ($fail === 'live' && !$archive)) {
			return false;
		}
	}

	return true;
}

function db_execute($sql, ...$args) { return record($sql); }
function db_execute_prepared($sql, $params = array(), ...$args) { return record($sql); }
function db_fetch_cell($sql, ...$args) { record($sql); return 0; }
function db_fetch_cell_prepared($sql, $params = array(), ...$args) { record($sql); return 0; }

function db_fetch_assoc_prepared($sql, $params = array(), ...$args) {
	record($sql);

	// One page, then nothing, so the paging loop terminates. Each row carries a
	// distinct timestamp, because the mid-page flush only fires on a change of
	// timestamp once the buffer is over length.
	if ($GLOBALS['stage_pages']-- > 0) {
		$rows = array();

		for ($i = 0; $i < $GLOBALS['stage_rows']; $i++) {
			$rows[] = array(
				'local_data_id'    => 1,
				'data_template_id' => 1,
				'timestamp'        => 1700000000 + ($i * 300),
				'rrd_name'         => 'value',
				'output'           => '5',
			);
		}

		return $rows;
	}

	return array();
}

/** Run the flush with one staging step failing, and report what it did. */
function run(string $fail, int $buffer = 1000000, int $rows = 1) : array {
	$GLOBALS['config']        = array('library_path' => $GLOBALS['stage_library'], 'poller_id' => 1);
	$GLOBALS['stage_buffer']  = $buffer;
	$GLOBALS['stage_rows']    = $rows;
	$GLOBALS['stage_fail']    = $fail;
	$GLOBALS['stage_sql']     = array();
	$GLOBALS['stage_updates'] = array();
	$GLOBALS['stage_logs']    = array();
	$GLOBALS['stage_pages']   = 1;

	$pipe   = false;
	$result = boost_process_poller_output(1, $pipe);

	$deletes = array_values(array_filter($GLOBALS['stage_sql'], function ($sql) {
		return strpos($sql, 'DELETE FROM') === 0;
	}));

	return array(
		'result'  => $result,
		'updates' => $GLOBALS['stage_updates'],
		'deletes' => $deletes,
		'logs'    => $GLOBALS['stage_logs'],
	);
}

it('retains the rows and writes nothing when the staging table cannot be created', function () {
	$run = run('create');

	expect($run['updates'])->toBe(array());
	expect($run['deletes'])->toBe(array());
	expect($run['result'])->toBe(-1);
});

it('retains the rows when an archive copy fails', function () {
	$run = run('archive');

	expect($run['updates'])->toBe(array());
	expect($run['deletes'])->toBe(array());
	expect($run['result'])->toBe(-1);
});

it('retains the rows when the live copy fails', function () {
	$run = run('live');

	expect($run['updates'])->toBe(array());
	expect($run['deletes'])->toBe(array());
	expect($run['result'])->toBe(-1);
});

it('says in the log why the rows were retained', function () {
	foreach (array('create', 'archive', 'live') as $fail) {
		$logs = implode(' | ', run($fail)['logs']);

		expect($logs)->toContain('could not stage');
	}
});

it('writes and deletes normally when staging succeeds', function () {
	$run = run('none');

	expect($run['updates'])->not->toBe(array());
	expect($run['deletes'])->not->toBe(array());
	expect($run['result'])->not->toBe(-1);
});

it('does not flush mid page once the run has given up', function () {
	// A tiny buffer and several timestamps make the mid-page flush reachable;
	// without the gate it writes the live samples while the archived ones are
	// retained, and rrdtool then rejects the older retained samples for good.
	$busy = run('none', 1, 4);

	expect($busy['updates'])->not->toBe(array());

	foreach (array('create', 'archive', 'live') as $fail) {
		$run = run($fail, 1, 4);

		expect($run['updates'])->toBe(array(), 'mid-page flush ran after ' . $fail . ' staging failure');
		expect($run['deletes'])->toBe(array());
		expect($run['result'])->toBe(-1);
	}
});
