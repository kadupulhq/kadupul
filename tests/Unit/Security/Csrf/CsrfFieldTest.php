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

$basePath = dirname(__DIR__, 4);
$GLOBALS['config'] = array(
	'base_path'    => $basePath,
	'include_path' => $basePath . '/include',
	'is_web'       => false,
);
$config = $GLOBALS['config'];

require_once($basePath . '/include/csrf.php');

beforeEach(function () {
	$GLOBALS['csrf']['disable'] = false;
	$GLOBALS['csrf']['secret'] = str_repeat('a', 64);
	$GLOBALS['csrf']['key'] = 'csrf-field-test-key';
	$GLOBALS['csrf']['user'] = false;
	$GLOBALS['csrf']['cookie'] = false;
	$GLOBALS['csrf']['session'] = false;
	$GLOBALS['csrf']['auto-session'] = false;
	$GLOBALS['csrf']['allow-ip'] = false;
	$GLOBALS['csrf']['expires'] = 7200;
	$GLOBALS['csrf']['hash'] = 'sha256';
	$GLOBALS['csrf']['xhtml'] = true;
	$GLOBALS['csrf']['frame-breaker'] = false;
	$GLOBALS['csrf']['rewrite-js'] = false;
	$GLOBALS['csrf']['log_file'] = '';
});

afterEach(function () {
	$GLOBALS['csrf']['disable'] = true;
	$GLOBALS['csrf']['key'] = false;
	$GLOBALS['csrf']['auto-session'] = true;
	$GLOBALS['csrf']['frame-breaker'] = true;
});

function csrf_field_output() {
	ob_start();
	csrf_field();

	return ob_get_clean();
}

test('csrf_field prints the field the output handler adds', function () {
	$field = csrf_field_output();
	$page  = csrf_ob_handler("<html><body><form method='post' action='graphs.php'></form></body></html>", 0);

	// A request without cookies also gets the address-bound part csrf-magic adds.
	expect($field)->toMatch("/^<input type='hidden' name='__csrf_magic' value=\"key:[0-9a-f]{64},[0-9]+(;ip:[0-9a-f]{64},[0-9]+)?\" \\/>$/")
		->and($page)->toBe("<html><body><form method='post' action='graphs.php'>" . $field . '</form></body></html>');
});

test('the printed token passes the check csrf-magic runs on POST', function () {
	preg_match('/value="([^"]+)"/', csrf_field_output(), $matches);

	expect(csrf_check_tokens($matches[1]))->toBeTrue();
});

test('csrf_field prints nothing where CSRF checks are disabled', function () {
	$GLOBALS['csrf']['disable'] = true;

	expect(csrf_field_output())->toBe('');
});
