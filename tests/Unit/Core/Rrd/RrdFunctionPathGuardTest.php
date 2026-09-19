<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Without Boost the poller hands poller cache paths to
 * rrdtool_function_update(), which may call rrdtool_function_create(). Both
 * must refuse a '..' segment as Boost does, and still accept custom locations
 * outside the RRA directory.
 */

$root = dirname(__DIR__, 4);

foreach (array('RRDTOOL_OUTPUT_STDOUT' => 1, 'RRDTOOL_OUTPUT_BOOLEAN' => 3) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function rrdPathGuard_get_data_source_path($local_data_id, $expand_paths) {
	return $GLOBALS['rrd_path_guard']['data_source_path'];
}

function rrdPathGuard_cacti_rrdtool_valid_path($path) {
	return is_string($path) && $path !== '' && !preg_match('/[\x00-\x1f\x7f]/', $path);
}

function rrdPathGuard_read_config_option($name) {
	return '';
}

function rrdPathGuard_rrdtool_execute_path_command($command, $path) {
	$GLOBALS['rrd_path_guard']['path_commands'][] = array($command, $path);

	return file_exists($path);
}

function rrdPathGuard_cacti_log($message) {
	$GLOBALS['rrd_path_guard']['logs'][] = $message;
}

function rrdPathGuard_cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function rrdPathGuard_cacti_rrdtool_valid_ds_name($name) {
	return (bool) preg_match('/^[a-zA-Z0-9_]{1,19}$/D', $name);
}

function rrdPathGuard_cacti_rrdtool_valid_ds_template($template) {
	return $template !== '' && strpos($template, ' ') === false;
}

function rrdPathGuard_cacti_has_control_chars($value) {
	return preg_match('/[\x00-\x1f\x7f]/', (string) $value) === 1;
}

function rrdPathGuard_get_rrdtool_version() {
	return '1.7';
}

function rrdPathGuard_cacti_version_compare($a, $b, $operator) {
	return version_compare($a, $b, $operator);
}

function rrdPathGuard_rrdtool_execute($command) {
	$GLOBALS['rrd_path_guard']['executed'][] = $command;

	return true;
}

function rrdPathGuardLoad($root) {
	if (function_exists('rrdPathGuard_rrdtool_function_update')) {
		return;
	}

	$source = file_get_contents($root . '/lib/rrd.php');

	if (!function_exists('rrd_check_path')) {
		preg_match('/^function rrd_check_path\(.*?^}\n/ms', $source, $match);
		eval($match[0]);
	}

	foreach (array('rrdtool_function_create', 'rrdtool_function_update') as $name) {
		$start = strpos($source, 'function ' . $name . '(');
		$end   = strpos($source, "\nfunction ", $start + 1);

		expect($start)->not->toBeFalse()
			->and($end)->not->toBeFalse();

		eval(preg_replace('/\b(rrdtool_function_create|rrdtool_function_update|get_data_source_path|cacti_rrdtool_valid_path|read_config_option|rrdtool_execute_path_command|rrdtool_execute|cacti_log|cacti_sizeof|cacti_rrdtool_valid_ds_name|cacti_rrdtool_valid_ds_template|cacti_has_control_chars|get_rrdtool_version|cacti_version_compare)\(/', 'rrdPathGuard_$1(', substr($source, $start, $end - $start)));
	}
}

function rrdPathGuardCache($path) {
	return array($path => array('local_data_id' => 12, 'data_template_id' => 0, 'times' => array(1000 => array('ds' => '1'))));
}

beforeEach(function () use ($root) {
	rrdPathGuardLoad($root);

	$GLOBALS['rrd_path_guard'] = array('executed' => array(), 'path_commands' => array(), 'logs' => array(), 'data_source_path' => '');

	$this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$this->tmp          = sys_get_temp_dir() . '/rrd-path-guard-' . bin2hex(random_bytes(4));

	mkdir($this->tmp . '/rra/5', 0700, true);
	mkdir($this->tmp . '/outside', 0700);
	mkdir($this->tmp . '/include', 0700);
	touch($this->tmp . '/rra/5/12.rrd');
	touch($this->tmp . '/outside/evil.rrd');
	touch($this->tmp . '/include/global_arrays.php');

	$GLOBALS['config']['rra_path']     = $this->tmp . '/rra';
	$GLOBALS['config']['include_path'] = $this->tmp . '/include';
});

afterEach(function () {
	$GLOBALS['config'] = $this->saved_config;

	@unlink($this->tmp . '/rra/5/12.rrd');
	@unlink($this->tmp . '/outside/evil.rrd');
	@unlink($this->tmp . '/include/global_arrays.php');
	@rmdir($this->tmp . '/rra/5');
	@rmdir($this->tmp . '/rra');
	@rmdir($this->tmp . '/outside');
	@rmdir($this->tmp . '/include');
	@rmdir($this->tmp);
});

test('the poller refuses to update an existing RRD reached through traversal', function () {
	expect(rrdPathGuard_rrdtool_function_update(rrdPathGuardCache($this->tmp . '/rra/../outside/evil.rrd')))->toBeFalse()
		->and($GLOBALS['rrd_path_guard']['executed'])->toBe(array())
		->and($GLOBALS['rrd_path_guard']['logs'][0])->toBe('ERROR: Invalid RRD file path in poller cache for local_data_id: 12.')
		->and($GLOBALS['rrd_path_guard']['logs'][1])->toContain('Invalid RRD sample path (not written)');
});

test('the poller still updates an RRD under the RRA directory and at a custom location', function () {
	$default = $this->tmp . '/rra/5/12.rrd';
	$custom  = $this->tmp . '/outside/evil.rrd';

	expect(rrdPathGuard_rrdtool_function_update(rrdPathGuardCache($default) + rrdPathGuardCache($custom)))->toBe(2)
		->and($GLOBALS['rrd_path_guard']['executed'])->toHaveCount(2)
		->and($GLOBALS['rrd_path_guard']['executed'][0])->toStartWith('update ' . $default . ' ')
		->and($GLOBALS['rrd_path_guard']['executed'][1])->toStartWith('update ' . $custom . ' ');
});

test('create refuses a data source path with a .. segment before touching it', function () {
	$GLOBALS['rrd_path_guard']['data_source_path'] = $this->tmp . '/rra/../outside/evil.rrd';

	expect(rrdPathGuard_rrdtool_function_create(12, false))->toBeFalse()
		->and($GLOBALS['rrd_path_guard']['path_commands'])->toBe(array())
		->and($GLOBALS['rrd_path_guard']['logs'])->toBe(array('ERROR: Invalid RRD file path for local_data_id: 12.'));
});

test('create still finds an existing RRD at a custom location', function () {
	$GLOBALS['rrd_path_guard']['data_source_path'] = $this->tmp . '/outside/evil.rrd';

	expect(rrdPathGuard_rrdtool_function_create(12, false))->toBe(-1)
		->and($GLOBALS['rrd_path_guard']['logs'])->toBe(array());
});


test('local invalid samples are consumed without blocking valid sibling samples', function ($kind) {
    $path = $this->tmp . '/rra/5/12.rrd';
    $invalid_time = $kind === 'time' ? 'invalid-time' : 1000;
    $bad = $kind === 'ds' ? array('bad ds' => '1') : ($kind === 'template' ? array() : array('ds' => '1'));
    $updates = array($path => array('local_data_id' => 12, 'data_template_id' => 0, 'times' => array($invalid_time => $bad, 1001 => array('ds' => '2'))));
    expect(rrdPathGuard_rrdtool_function_update($updates, false, $completed))->toBeFalse();
    expect($completed[$path])->toHaveCount(2);
    expect($GLOBALS['rrd_path_guard']['executed'])->toHaveCount(1);
    expect($GLOBALS['rrd_path_guard']['executed'][0])->toContain('1001:2');
    expect(implode("\n", $GLOBALS['rrd_path_guard']['logs']))->toContain('not written');
})->with(array('time','ds','template'));
