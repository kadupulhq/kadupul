<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Executable cover for the restored per data source lock. The companion test
 * asserts the shape of the source; this one runs the real function with the
 * lock refused, so an inverted version check or a missed cleanup path shows up
 * as behaviour rather than passing a text match.
 *
 * The lock only exists for rrdtool before 1.5, which lacks --skip-past-updates,
 * so the version is driven from the stub rather than the host's binary.
 */

namespace BoostSingleDsLockNative;

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
$GLOBALS['lock_library'] = sys_get_temp_dir() . '/boost-lock-' . getmypid() . '-' . mt_rand();
mkdir($GLOBALS['lock_library'], 0700, true);
file_put_contents($GLOBALS['lock_library'] . '/rrd.php', '<?php');

register_shutdown_function(static function () {
	@unlink($GLOBALS['lock_library'] . '/rrd.php');
	@rmdir($GLOBALS['lock_library']);
});

function set_error_handler($handler) {}
function restore_error_handler() {}
function cacti_system_zone_set() {}
function boost_error_handler(...$args) { return true; }
function get_rrdtool_version() { return $GLOBALS['lock_version']; }
function cacti_version_compare($a, $b, $op) { return version_compare($a, $b, $op); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_count($value) { return cacti_sizeof($value); }
function boost_timer(...$args) {}
function boost_debug(...$args) {}
function cacti_log($message, ...$args) { $GLOBALS['lock_logs'][] = $message; }
function boost_get_arch_table_names($table) { return array(); }
function boost_get_unused_data_source_names($id) { return array(); }
function boost_get_rrd_filename_and_template($id) { return array('rrd_template' => 'value', 'rrd_path' => "/rra/$id.rrd"); }
function boost_rrdtool_pipe_creates(...$args) {}
function rrd_init(...$args) { return fopen('php://temp', 'r+'); }
function rrd_close($pipe) { if (is_resource($pipe)) { fclose($pipe); } }
function db_affected_rows(...$args) { return 1; }
function debounce_run_notification(...$args) {}
function is_hexadecimal($value) { return false; }
function boost_get_input_field_names($id) { return array('value'); }
function boost_limit_complete_timestamp_page($results, $last_page) { return $results; }
function boost_rrdtool_function_update($id, $path, $template, $values, $pipe) { return 'OK'; }

function read_config_option($name) {
	if ($name === 'boost_rrd_update_string_length') {
		return 1000000;
	}

	if ($name === 'boost_rrd_update_max_runtime') {
		return $GLOBALS['lock_runtime'];
	}

	return '';
}

function note($sql) {
	$GLOBALS['lock_sql'][] = preg_replace('/\s+/', ' ', trim($sql));
}

function db_execute($sql, ...$args) { note($sql); return true; }
function db_execute_prepared($sql, $params = array(), ...$args) { note($sql); return true; }
function db_fetch_cell_prepared($sql, $params = array(), ...$args) { note($sql); return 0; }

function db_fetch_cell($sql, ...$args) {
	note($sql);

	// The lock is refused for as long as the case asks it to be.
	if (strpos($sql, 'GET_LOCK') !== false) {
		return $GLOBALS['lock_granted'] ? 1 : 0;
	}

	return 0;
}

function db_fetch_assoc_prepared($sql, $params = array(), ...$args) {
	note($sql);

	if ($GLOBALS['lock_pages']-- > 0) {
		return array(array(
			'local_data_id'    => 1,
			'data_template_id' => 1,
			'timestamp'        => 1700000000,
			'rrd_name'         => 'value',
			'output'           => '5',
		));
	}

	return array();
}

function run(string $version, bool $granted, $runtime = 1) : array {
	$GLOBALS['config']       = array('library_path' => $GLOBALS['lock_library'], 'poller_id' => 1);
	$GLOBALS['lock_version'] = $version;
	$GLOBALS['lock_granted'] = $granted;
	$GLOBALS['lock_runtime'] = $runtime;
	$GLOBALS['lock_sql']     = array();
	$GLOBALS['lock_logs']    = array();
	$GLOBALS['lock_pages']   = 1;

	$pipe   = false;
	$result = boost_process_poller_output(1, $pipe);

	$matching = static function ($needle) {
		return count(array_filter($GLOBALS['lock_sql'], static function ($sql) use ($needle) {
			return strpos($sql, $needle) !== false;
		}));
	};

	return array(
		'result'   => $result,
		'acquires' => $matching('GET_LOCK'),
		'releases' => $matching('RELEASE_LOCK'),
		'drops'    => $matching('DROP TEMPORARY TABLE'),
		'logs'     => implode(' | ', $GLOBALS['lock_logs']),
	);
}

it('gives up rather than spinning when the lock is never granted', function () {
	$run = run('1.4', false);

	expect($run['result'])->toBe(-1);
	expect($run['acquires'])->toBeGreaterThan(0);
	expect($run['logs'])->toContain('timed out acquiring the RRD lock');
});

it('releases nothing it did not acquire on the timeout path', function () {
	$run = run('1.4', false);

	// The release is gated on the acquisition flag, so a refused lock must not
	// issue one, and the staging table is dropped from the finally regardless.
	expect($run['releases'])->toBe(0);
});

it('acquires and then releases when the lock is granted', function () {
	$run = run('1.4', true);

	expect($run['acquires'])->toBeGreaterThan(0);
	expect($run['releases'])->toBe(1);
	expect($run['result'])->not->toBe(-1);
});

it('takes no lock at all on rrdtool 1.5 or newer', function () {
	$run = run('1.7.2', false);

	// 1.5 added --skip-past-updates, which is why the lock exists only below it.
	// An inverted version check would show up here as an acquisition.
	expect($run['acquires'])->toBe(0);
	expect($run['releases'])->toBe(0);
	expect($run['result'])->not->toBe(-1);
});

it('keeps a one second floor when the configured runtime is unusable', function () {
	foreach (array(0, '', 'not-a-number') as $runtime) {
		$run = run('1.4', false, $runtime);

		// max(1, ...) means the loop still runs and still gives up.
		expect($run['result'])->toBe(-1);
		expect($run['logs'])->toContain('timed out acquiring the RRD lock');
	}
});
