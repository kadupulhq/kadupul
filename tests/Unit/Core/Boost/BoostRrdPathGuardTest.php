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

$root = dirname(__DIR__, 4);

foreach (array('RRDTOOL_OUTPUT_STDOUT' => 1, 'RRDTOOL_OUTPUT_BOOLEAN' => 3, 'POLLER_VERBOSITY_NONE' => 1, 'POLLER_VERBOSITY_HIGH' => 4) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function boostRrdGuard_cacti_rrdtool_valid_path($path) {
	return is_string($path) && $path !== '' && !preg_match('/[\x00-\x1f\x7f]/', $path);
}

function boostRrdGuard_read_config_option($name) {
	return '';
}

function boostRrdGuard_rrdtool_execute_path_command($command, $path) {
	$GLOBALS['boost_rrd_guard']['path_commands'][] = array($command, $path);

	return $command == 'last' ? '1234' : file_exists($path);
}

function boostRrdGuard_db_fetch_cell_prepared($sql, $params = array()) {
	return 1;
}

function boostRrdGuard_boost_rrdtool_function_create($local_data_id, $show_source, &$rrdtool_pipe) {
	return false;
}

function boostRrdGuard_get_rrdtool_version() {
	return '1.7';
}

function boostRrdGuard_cacti_version_compare($a, $b, $operator) {
	return version_compare($a, $b, $operator);
}

function boostRrdGuard_cacti_rrdtool_valid_ds_template($template) {
	return true;
}

function boostRrdGuard_cacti_has_control_chars($value) {
	return preg_match('/[\x00-\x1f\x7f]/', (string) $value) === 1;
}

function boostRrdGuard_cacti_log($message) {
	$GLOBALS['boost_rrd_guard']['logs'][] = $message;
}

function boostRrdGuard_rrdtool_last_rejection() { return null; }
function boostRrdGuard_rrdtool_rejection_is_permanent($reason) { return false; }

function boostRrdGuard_rrdtool_execute($command) {
	$GLOBALS['boost_rrd_guard']['executed'][] = $command;

	return true;
}

function boostRrdGuardLoad($root) {
	if (function_exists('boostRrdGuard_boost_rrdtool_function_update')) {
		return;
	}

	if (!function_exists('rrd_check_path')) {
		$rrd = file_get_contents($root . '/lib/rrd.php');
		preg_match('/^function rrd_check_path\(.*?^}\n/ms', $rrd, $match);
		eval($match[0]);
	}

	$source = file_get_contents($root . '/lib/boost.php');

	foreach (array('boost_rrdtool_function_update', 'boost_rrdtool_get_last_update_time') as $name) {
		$start = strpos($source, 'function ' . $name . '(');
		$end   = strpos($source, "\nfunction ", $start + 1);

		expect($start)->not->toBeFalse()
			->and($end)->not->toBeFalse();

		$function = preg_replace('/\b(rrdtool_last_rejection|rrdtool_rejection_is_permanent|boost_rrdtool_function_update|boost_rrdtool_get_last_update_time|boost_rrdtool_function_create|rrdtool_execute_path_command|rrdtool_execute|cacti_rrdtool_valid_ds_template|cacti_rrdtool_valid_path|cacti_has_control_chars|cacti_version_compare|get_rrdtool_version|read_config_option|db_fetch_cell_prepared|cacti_log)\(/', 'boostRrdGuard_$1(', substr($source, $start, $end - $start));

		eval($function);
	}
}

beforeEach(function () use ($root) {
	boostRrdGuardLoad($root);

	$GLOBALS['boost_rrd_guard'] = array('executed' => array(), 'path_commands' => array(), 'logs' => array());

	$this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$this->tmp          = sys_get_temp_dir() . '/boost-rrd-guard-' . bin2hex(random_bytes(4));

	mkdir($this->tmp . '/rra/5', 0700, true);
	mkdir($this->tmp . '/outside', 0700);
	touch($this->tmp . '/rra/5/12.rrd');
	touch($this->tmp . '/outside/evil.rrd');

	$GLOBALS['config']['rra_path'] = $this->tmp . '/rra';
});

afterEach(function () {
	$GLOBALS['config'] = $this->saved_config;

	@unlink($this->tmp . '/storage');
	@unlink($this->tmp . '/rra/7');
	@unlink($this->tmp . '/rra/5/12.rrd');
	@unlink($this->tmp . '/outside/evil.rrd');
	@rmdir($this->tmp . '/rra/5');
	@rmdir($this->tmp . '/rra');
	@rmdir($this->tmp . '/outside');
	@rmdir($this->tmp);
});

test('Boost refuses to update an existing RRD reached through traversal', function () {
	$pipe   = false;
	$values = ' 1000:1';

	expect(boostRrdGuard_boost_rrdtool_function_update(12, $this->tmp . '/rra/../outside/evil.rrd', '', $values, $pipe))->toBe('ERROR')
		->and($GLOBALS['boost_rrd_guard']['executed'])->toBe(array());
});

test('Boost still updates an existing RRD at a custom location outside the RRA directory', function () {
	$pipe   = false;
	$values = ' 1000:1';

	expect(boostRrdGuard_boost_rrdtool_function_update(12, $this->tmp . '/outside/evil.rrd', '', $values, $pipe))->toBe('OK')
		->and($GLOBALS['boost_rrd_guard']['executed'])->toHaveCount(1);
});

test('Boost still updates an RRD under the default RRA directory', function () {
	$pipe   = false;
	$values = ' 1000:1';
	$path   = $this->tmp . '/rra/5/12.rrd';

	expect(boostRrdGuard_boost_rrdtool_function_update(12, $path, '', $values, $pipe))->toBe('OK')
		->and($GLOBALS['boost_rrd_guard']['executed'])->toHaveCount(1)
		->and($GLOBALS['boost_rrd_guard']['executed'][0])->toStartWith('update ' . $path . ' ');
});

test('Boost still updates an RRD when the RRA directory is a symlink to other storage', function () {
	$pipe   = false;
	$values = ' 1000:1';
	$link   = $this->tmp . '/storage';

	expect(symlink($this->tmp . '/rra', $link))->toBeTrue();

	$GLOBALS['config']['rra_path'] = $link;

	expect(boostRrdGuard_boost_rrdtool_function_update(12, $link . '/5/12.rrd', '', $values, $pipe))->toBe('OK')
		->and($GLOBALS['boost_rrd_guard']['executed'])->toHaveCount(1);
});

test('Boost still updates an RRD in a symlinked subdirectory under the RRA directory', function () {
	$pipe   = false;
	$values = ' 1000:1';

	expect(symlink($this->tmp . '/outside', $this->tmp . '/rra/7'))->toBeTrue();

	expect(boostRrdGuard_boost_rrdtool_function_update(12, $this->tmp . '/rra/7/evil.rrd', '', $values, $pipe))->toBe('OK')
		->and($GLOBALS['boost_rrd_guard']['executed'])->toHaveCount(1);
});

test('Boost reads the last update except for a traversal path', function () {
	$pipe = false;

	boostRrdGuard_boost_rrdtool_get_last_update_time($this->tmp . '/rra/../outside/evil.rrd', $pipe);

	expect($GLOBALS['boost_rrd_guard']['path_commands'])->toBe(array());

	expect(boostRrdGuard_boost_rrdtool_get_last_update_time($this->tmp . '/outside/evil.rrd', $pipe))->toBe('1234')
		->and(boostRrdGuard_boost_rrdtool_get_last_update_time($this->tmp . '/rra/5/12.rrd', $pipe))->toBe('1234')
		->and($GLOBALS['boost_rrd_guard']['path_commands'])->toBe(array(array('last', $this->tmp . '/outside/evil.rrd'), array('last', $this->tmp . '/rra/5/12.rrd')));
});
