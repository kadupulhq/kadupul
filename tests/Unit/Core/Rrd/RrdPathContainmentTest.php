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
 * rrd_check_containment() accepts every path while 'Restrict RRDfiles to the
 * RRA Directory' is off, as in 1.2.31. When it is on, a path must resolve
 * inside the RRA directory: links are followed, a file that does not exist
 * yet is judged by the directory that will hold it, and a dangling link is
 * refused. The Data Source form checks the stored path after the same
 * expansion get_data_source_path() applies.
 */

$root = dirname(__DIR__, 4);

require_once $root . '/tests/Helpers/RrdContainmentSource.php';

test_load_rrd_check_containment($root);

if (!function_exists('expand_data_source_path')) {
	eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'expand_data_source_path'));
}

beforeEach(function () {
	$this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$this->tmp          = realpath(sys_get_temp_dir()) . '/rrd-containment-' . bin2hex(random_bytes(4));

	mkdir($this->tmp . '/store/rra/5', 0700, true);
	mkdir($this->tmp . '/outside', 0700);
	touch($this->tmp . '/store/rra/5/12.rrd');
	touch($this->tmp . '/outside/other.rrd');
	symlink($this->tmp . '/outside', $this->tmp . '/store/rra/away');
	symlink($this->tmp . '/store/rra/5', $this->tmp . '/store/rra/near');
	symlink($this->tmp . '/missing.rrd', $this->tmp . '/store/rra/dangling.rrd');
	/* a cacti tree whose rra directory is a link to storage elsewhere */
	mkdir($this->tmp . '/cacti', 0700);
	symlink($this->tmp . '/store/rra', $this->tmp . '/cacti/rra');

	$GLOBALS['config']['rra_path']             = $this->tmp . '/cacti/rra';
	$GLOBALS['test_rrd_containment_settings'] = array('rrd_path_containment' => 'on');
});

afterEach(function () {
	$GLOBALS['config'] = $this->saved_config;

	foreach (array('/cacti/rra', '/store/rra/away', '/store/rra/near', '/store/rra/dangling.rrd', '/store/rra/5/12.rrd', '/outside/other.rrd') as $file) {
		@unlink($this->tmp . $file);
	}

	foreach (array('/cacti', '/store/rra/5', '/store/rra', '/store', '/outside', '') as $dir) {
		@rmdir($this->tmp . $dir);
	}
});

test('with the restriction off every path is accepted as in 1.2.31', function () {
	$GLOBALS['test_rrd_containment_settings'] = array();

	expect(rrd_check_containment($this->tmp . '/outside/other.rrd'))->toBeTrue()
		->and(rrd_check_containment($this->tmp . '/cacti/rra/away/other.rrd'))->toBeTrue()
		->and(rrd_check_containment('/nonexistent/place/x.rrd'))->toBeTrue();
});

test('with the restriction on a path inside the RRA directory is accepted', function (string $relative) {
	expect(rrd_check_containment($this->tmp . $relative))->toBeTrue();
})->with(array(
	'existing file'               => array('/cacti/rra/5/12.rrd'),
	'file to be created'          => array('/cacti/rra/5/13.rrd'),
	'new structured directories'  => array('/cacti/rra/9/4/13.rrd'),
	'link that stays inside'      => array('/cacti/rra/near/12.rrd'),
	'resolved storage location'   => array('/store/rra/5/12.rrd'),
));

test('with the restriction on a path that leaves the RRA directory is refused', function (string $relative) {
	expect(rrd_check_containment($this->tmp . $relative))->toBeFalse();
})->with(array(
	'custom location'             => array('/outside/other.rrd'),
	'new file at a custom place'  => array('/outside/new.rrd'),
	'link that leads outside'     => array('/cacti/rra/away/other.rrd'),
	'new file behind that link'   => array('/cacti/rra/away/new.rrd'),
	'dangling link'               => array('/cacti/rra/dangling.rrd'),
	'traversal'                   => array('/cacti/rra/5/../../../outside/other.rrd'),
	'sibling with a shared prefix' => array('/cacti/rra-old/5/12.rrd'),
));

test('with the restriction on an empty path or a NUL byte is refused', function () {
	expect(rrd_check_containment(''))->toBeFalse()
		->and(rrd_check_containment($this->tmp . "/cacti/rra/5/12.rrd\0"))->toBeFalse();
});

test('the Data Source form value is expanded as get_data_source_path() expands it', function (string $stored, string $expected, bool $allowed) {
	$expected = str_replace('{rra}', $GLOBALS['config']['rra_path'], str_replace('{tmp}', $this->tmp, $expected));

	expect(expand_data_source_path(str_replace('{tmp}', $this->tmp, $stored)))->toBe($expected)
		->and(rrd_check_containment($expected))->toBe($allowed);
})->with(array(
	'bare file name'        => array('localhost_load_7.rrd', '{rra}/localhost_load_7.rrd', true),
	'path_rra token'        => array('<path_rra>/5/12.rrd', '{rra}/5/12.rrd', true),
	'absolute custom path'  => array('{tmp}/outside/other.rrd', '{tmp}/outside/other.rrd', false),
));

test('expand_data_source_path keeps the token when expansion is not asked for', function () {
	expect(expand_data_source_path('x.rrd', false))->toBe('<path_rra>/x.rrd')
		->and(expand_data_source_path('<path_rra>/5/12.rrd', false))->toBe('<path_rra>/5/12.rrd');
});

test('the Data Source form refuses a path outside the RRA directory through the shared check', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/data_sources.php');

	expect($source)->toContain("rrd_check_containment(expand_data_source_path(\$save2['data_source_path']))")
		->and($source)->toContain("\$_SESSION['sess_error_fields']['data_source_path'] = 'data_source_path';");
});
