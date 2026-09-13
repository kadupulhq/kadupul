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
 * poller_boost.php writes create and update commands to one rrdtool pipe, and
 * rrdtool reads them later.  A new RRD therefore does not exist when the update
 * is queued.  1.2.31 accepted that; a failure here marks the whole Boost run
 * failed and keeps the archive tables.
 */

$root = dirname(__DIR__, 4);

foreach (array('RRDTOOL_OUTPUT_STDOUT' => 1, 'RRDTOOL_OUTPUT_BOOLEAN' => 4, 'POLLER_VERBOSITY_NONE' => 1, 'POLLER_VERBOSITY_HIGH' => 4) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function boostPipedCreate_cacti_rrdtool_valid_path($path) {
	return is_string($path) && $path !== '' && !preg_match('/[\x00-\x1f\x7f]/', $path);
}

function boostPipedCreate_read_config_option($name) {
	return '';
}

function boostPipedCreate_rrdtool_execute_path_command($command, $path) {
	return file_exists($path);
}

function boostPipedCreate_db_fetch_cell_prepared($sql, $params = array()) {
	return 1;
}

function boostPipedCreate_boost_rrdtool_function_create($local_data_id, $show_source, &$rrdtool_pipe) {
	$state =& $GLOBALS['boost_piped_create'];
	$state['creates']++;

	if ($state['create_writes_file']) {
		touch($state['path']);
	}

	return $state['create_return'];
}

function boostPipedCreate_get_rrdtool_version() {
	return '1.7';
}

function boostPipedCreate_cacti_version_compare($a, $b, $operator) {
	return version_compare($a, $b, $operator);
}

function boostPipedCreate_cacti_rrdtool_valid_ds_template($template) {
	return true;
}

function boostPipedCreate_cacti_has_control_chars($value) {
	return preg_match('/[\x00-\x1f\x7f]/', (string) $value) === 1;
}

function boostPipedCreate_cacti_log($message) {
}

function boostPipedCreate_rrdtool_execute($command) {
	$GLOBALS['boost_piped_create']['executed'][] = $command;

	/* a piped command returns nothing */
	return null;
}

function boostPipedCreateLoad($root) {
	if (function_exists('boostPipedCreate_boost_rrdtool_function_update')) {
		return;
	}

	if (!function_exists('rrd_check_path')) {
		preg_match('/^function rrd_check_path\(.*?^}\n/ms', file_get_contents($root . '/lib/rrd.php'), $match);
		eval($match[0]);
	}

	$source = file_get_contents($root . '/lib/boost.php');
	$start  = strpos($source, 'function boost_rrdtool_function_update(');
	$end    = strpos($source, "\nfunction ", $start + 1);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	eval(preg_replace('/\b(boost_rrdtool_function_update|boost_rrdtool_function_create|rrdtool_execute_path_command|rrdtool_execute|cacti_rrdtool_valid_ds_template|cacti_rrdtool_valid_path|cacti_has_control_chars|cacti_version_compare|get_rrdtool_version|read_config_option|db_fetch_cell_prepared|cacti_log)\(/', 'boostPipedCreate_$1(', substr($source, $start, $end - $start)));
}

/* The acknowledgement test Boost applies to this return value. */
function boostPipedCreateFails($return_value) {
	return trim((string) $return_value) !== 'OK';
}

beforeEach(function () use ($root) {
	boostPipedCreateLoad($root);

	$this->tmp  = sys_get_temp_dir() . '/boost-piped-create-' . bin2hex(random_bytes(4));
	$this->pipe = fopen('php://memory', 'w');
	mkdir($this->tmp . '/rra', 0700, true);

	$GLOBALS['boost_piped_create'] = array(
		'path'               => $this->tmp . '/rra/12.rrd',
		'creates'            => 0,
		'create_return'      => null,
		'create_writes_file' => false,
		'executed'           => array(),
	);
});

afterEach(function () {
	if (is_resource($this->pipe)) {
		fclose($this->pipe);
	}

	@unlink($this->tmp . '/rra/12.rrd');
	@rmdir($this->tmp . '/rra');
	@rmdir($this->tmp);
});

test('a new RRD created through the rrdtool pipe is updated and acknowledged', function () {
	$values = ' 1000:1 1300:2';
	$path   = $GLOBALS['boost_piped_create']['path'];

	$result = boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe);

	expect($result)->toBe('OK')
		->and(boostPipedCreateFails($result))->toBeFalse()
		->and($GLOBALS['boost_piped_create']['creates'])->toBe(1)
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(1)
		->and($GLOBALS['boost_piped_create']['executed'][0])->toStartWith('update ' . $path . ' ');
});

test('later updates for the same new RRD on one pipe do not queue another create', function () {
	$path = $GLOBALS['boost_piped_create']['path'];

	for ($i = 0; $i < 3; $i++) {
		$values = ' ' . (1000 + $i * 300) . ':1';

		expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $this->pipe))->toBe('OK');
	}

	/* rrdtool create overwrites an existing file, so a second create would drop the queued updates */
	expect($GLOBALS['boost_piped_create']['creates'])->toBe(1)
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(3);
});

test('a create that Boost refused still fails on the pipe', function () {
	$values = ' 1000:1';

	$GLOBALS['boost_piped_create']['create_return'] = false;

	$result = boostPipedCreate_boost_rrdtool_function_update(12, $GLOBALS['boost_piped_create']['path'], '', $values, $this->pipe);

	expect($result)->toBe('ERROR: Unable to create RRD file')
		->and($GLOBALS['boost_piped_create']['executed'])->toBe(array());
});

test('without a pipe the create is still confirmed on disk', function () {
	$values = ' 1000:1';
	$pipe   = false;
	$path   = $GLOBALS['boost_piped_create']['path'];

	$GLOBALS['boost_piped_create']['create_return'] = '';

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $pipe))->toBe('ERROR: Unable to create RRD file')
		->and($GLOBALS['boost_piped_create']['executed'])->toBe(array());

	$GLOBALS['boost_piped_create']['create_writes_file'] = true;

	expect(boostPipedCreate_boost_rrdtool_function_update(12, $path, '', $values, $pipe))->toBe('OK')
		->and($GLOBALS['boost_piped_create']['executed'])->toHaveCount(1);
});
