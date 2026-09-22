<?php

/* Copyright (C) 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later */

namespace PackageImportProfileTest;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

class State {
	public static $request = array();
	public static $validated = false;
}
function validate_request_vars() { State::$validated = true; }
function isset_request_var($name) { return isset(State::$request[$name]); }
function get_nfilter_request_var($name) { return State::$request[$name]; }
function get_filter_request_var($name) { return (int) State::$request[$name]; }
function read_config_option($name) { return 1; }
function __($text, ...$args) { return $text; }

$source = file_get_contents(dirname(__DIR__, 4) . '/package_import.php');
eval('namespace PackageImportProfileTest; ' . test_php_function_source($source, 'get_import_form'));

test('package form receives the default profile rather than the package location', function ($location, $profile, $override) use ($source) {
	State::$request = array('package_location' => $location);
	State::$validated = false;
	if ($override !== null) { State::$request['data_source_profile'] = $override; }
	$GLOBALS['image_types'] = array(1 => 'PNG');
	$default_profile = $profile;
	expect(preg_match('/\$form = get_import_form\([^;]+;/', $source, $match))->toBe(1);
	eval('namespace PackageImportProfileTest; ' . $match[0]);
	expect(State::$validated)->toBeTrue();
	expect($form['data_source_profile']['default'])->toBe($profile);
	expect($form['data_source_profile']['value'])->toBe($override === null ? '' : (int) $override);
	// Keep location validation even though it is no longer an argument to the form builder.
	expect($source)->toContain("if (get_filter_request_var('package_location') == 0)");
})->with(array(0, 1))->with(array(7, 42))->with(array(null, '19'));
