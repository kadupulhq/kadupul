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
 * The maintenance poller archives or deletes the file a removed data source
 * pointed at. As with RRD creation on this line, a '..' segment is refused in
 * the source, the archive target and the archive directory, while custom
 * locations outside the RRA directory keep working as in 1.2.31.
 *
 * remove_files() is extracted from poller_maintenance.php, with
 * rrd_check_path() from lib/rrd.php, and run against a temporary tree with the
 * database and logging stubbed in this namespace, so other unit tests that
 * load lib/ files do not collide.
 */

namespace RrdMaintenanceTraversalTest;

if (!function_exists(__NAMESPACE__ . '\remove_files')) {
	$root = dirname(__DIR__, 2);

	$source = file_get_contents($root . '/poller_maintenance.php');
	preg_match('/^function remove_files\(.*?^}\n/ms', $source, $remove);
	preg_match('/^function rrdclean_create_path\(.*?^}\n/ms', $source, $create);

	$source = file_get_contents($root . '/lib/rrd.php');
	preg_match('/^function rrd_check_path\(.*?^}\n/ms', $source, $check);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $remove[0] . $create[0] . $check[0]);
}

function read_config_option($name, $force = false) {
	return $GLOBALS['rmt_settings'][$name] ?? '';
}

function maint_debug($message) {
}

function cacti_sizeof($array) {
	return is_array($array) ? count($array) : 0;
}

function db_fetch_assoc_prepared($sql, $params = array()) {
	return array();
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['rmt_log'][] = $message;
}

function cacti_log_safe_value($value) {
	return $value;
}

function db_execute_prepared($sql, $params = array()) {
	$GLOBALS['rmt_dropped'][] = $params[0];

	return true;
}

$purge = function (string $name, string $action): void {
	/* local_data_id 0 skips the data source and graph removal that follows the file step */
	remove_files(array(array('id' => 1, 'name' => $name, 'local_data_id' => 0, 'action' => $action)));
};

beforeEach(function () {
	$this->root = realpath(sys_get_temp_dir()) . '/rrd-maint-' . bin2hex(random_bytes(4));
	$this->base = $this->root . '/cacti';
	$this->rra  = $this->base . '/rra';

	mkdir($this->rra . '/5', 0700, true);
	mkdir($this->base . '/include', 0700);
	mkdir($this->base . '/custom', 0700);
	mkdir($this->root . '/outside', 0700);

	file_put_contents($this->rra . '/5/local_5.rrd', 'rrd');
	file_put_contents($this->rra . '/keep.rrd', 'rrd');
	file_put_contents($this->base . '/custom/local_7.rrd', 'rrd');
	file_put_contents($this->base . '/include/config.php', '<?php');
	file_put_contents($this->root . '/outside/other.rrd', 'rrd');

	$this->savedConfig = $GLOBALS['config'] ?? null;

	$GLOBALS['config'] = array(
		'base_path'       => $this->base,
		'rra_path'        => $this->rra,
		'cacti_server_os' => 'unix'
	);

	$GLOBALS['rmt_settings'] = array('storage_location' => 0, 'rrd_archive' => '');
	$GLOBALS['rmt_log']      = array();
	$GLOBALS['rmt_dropped']  = array();
	$GLOBALS['archived']     = 0;
	$GLOBALS['purged']       = 0;
});

afterEach(function () {
	if ($this->savedConfig === null) {
		unset($GLOBALS['config']);
	} else {
		$GLOBALS['config'] = $this->savedConfig;
	}

	$paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);

	foreach ($paths as $path) {
		($path->isDir() && !$path->isLink()) ? rmdir($path->getPathname()) : unlink($path->getPathname());
	}

	rmdir($this->root);
});

test('archives an RRD file under the RRA path as 1.2.31 did', function () use ($purge) {
	$purge('5/local_5.rrd', '3');

	expect(file_exists($this->rra . '/5/local_5.rrd'))->toBeFalse()
		->and(file_get_contents($this->rra . '/archive/5/local_5.rrd'))->toBe('rrd')
		->and($GLOBALS['archived'])->toBe(1);
});

test('deletes an RRD file under the RRA path as 1.2.31 did', function () use ($purge) {
	$purge('keep.rrd', '1');

	expect(file_exists($this->rra . '/keep.rrd'))->toBeFalse()
		->and($GLOBALS['purged'])->toBe(1);
});

test('archives a file at a custom location outside the RRA path as 1.2.31 did', function () use ($purge) {
	$purge('<path_cacti>/custom/local_7.rrd', '3');

	expect(file_exists($this->base . '/custom/local_7.rrd'))->toBeFalse()
		->and(file_get_contents($this->rra . '/archive/custom/local_7.rrd'))->toBe('rrd')
		->and($GLOBALS['archived'])->toBe(1);
});

test('deletes a file at a custom location outside the RRA path as 1.2.31 did', function () use ($purge) {
	$purge('<path_cacti>/custom/local_7.rrd', '1');

	expect(file_exists($this->base . '/custom/local_7.rrd'))->toBeFalse()
		->and($GLOBALS['purged'])->toBe(1);
});

test('does not delete a file reached through a .. segment', function () use ($purge) {
	$purge('../../outside/other.rrd', '1');

	expect(file_get_contents($this->root . '/outside/other.rrd'))->toBe('rrd')
		->and($GLOBALS['purged'])->toBe(0)
		->and(implode("\n", $GLOBALS['rmt_log']))->toContain('.. segment')
		->and($GLOBALS['rmt_dropped'])->toBe(array('../../outside/other.rrd'));
});

test('does not archive a file reached through a .. segment', function () use ($purge) {
	$purge('../include/config.php', '3');

	expect(file_get_contents($this->base . '/include/config.php'))->toBe('<?php')
		->and(is_dir($this->rra . '/include'))->toBeFalse()
		->and($GLOBALS['archived'])->toBe(0)
		->and(implode("\n", $GLOBALS['rmt_log']))->toContain('.. segment');
});

test('does not archive into a directory reached through a .. segment', function () use ($purge) {
	$GLOBALS['rmt_settings']['rrd_archive'] = $this->rra . '/archive/../../../outside';

	$purge('5/local_5.rrd', '3');

	expect(file_get_contents($this->rra . '/5/local_5.rrd'))->toBe('rrd')
		->and(file_exists($this->root . '/outside/5/local_5.rrd'))->toBeFalse()
		->and(is_dir($this->rra . '/archive'))->toBeFalse()
		->and($GLOBALS['archived'])->toBe(0)
		->and(implode("\n", $GLOBALS['rmt_log']))->toContain('.. segment');
});
