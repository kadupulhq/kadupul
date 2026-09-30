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
 * The classic theme draws external-link tabs with the DejaVu Bold fonts in
 * include/fonts. Windows listed only C:/Windows/Fonts/, which has no DejaVu,
 * so the tab lost its label and every page logged font warnings.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

$classicTabRoot = dirname(__DIR__, 4);

/**
 * @return array<int, string>
 */
function classic_tab_dejavu_paths(string $root, string $os) : array {
	$source = file_get_contents($root . '/include/global_arrays.php');

	expect(preg_match("/^if \\(\\\$config\\['cacti_server_os'\\] == 'unix'\\) \\{\\n\\t\\\$dejavu_paths = .*?^\\}\\n/ms", $source, $match))->toBe(1);

	$config = array('cacti_server_os' => $os);

	/* test-only eval of the shipped block, read from this repository */
	eval(str_replace('__DIR__', var_export($root . '/include', true), $match[0]));

	return $dejavu_paths;
}

function classic_tab_load(string $root) : void {
	if (!function_exists('get_classic_tabimage')) {
		/* test-only eval of the shipped function, read from this repository */
		eval(test_php_function_source(file_get_contents($root . '/lib/functions.php'), 'get_classic_tabimage'));
	}
}

test('the bundled fonts are the last font path on every platform', function (string $os) use ($classicTabRoot) {
	$paths = classic_tab_dejavu_paths($classicTabRoot, $os);

	expect(end($paths))->toBe($classicTabRoot . '/include/fonts')
		->and(is_file(end($paths) . '/DejaVuSans-Bold.ttf'))->toBeTrue();
})->with(array('unix', 'win32'));

test('Windows still lists the system font directory first', function () use ($classicTabRoot) {
	expect(classic_tab_dejavu_paths($classicTabRoot, 'win32')[0])->toBe('C:/Windows/Fonts/');
});

test('a classic tab on a Windows host draws its label with the bundled font', function () use ($classicTabRoot) {
	global $config, $dejavu_paths;

	classic_tab_load($classicTabRoot);

	$config       = array('base_path' => $classicTabRoot, 'cacti_server_os' => 'win32');
	$dejavu_paths = classic_tab_dejavu_paths($classicTabRoot, 'win32');
	$warnings     = array();

	set_error_handler(function ($errno, $errstr) use (&$warnings) {
		$warnings[] = $errstr;

		return true;
	});

	try {
		$tab   = get_classic_tabimage('Weathermap');
		$blank = get_classic_tabimage('');
	} finally {
		restore_error_handler();
	}

	expect($warnings)->toBe(array())
		->and($blank)->toBeFalse()
		->and($tab)->toStartWith('data:image/gif;base64,');

	$template = imagecreatefromgif($classicTabRoot . '/images/tab_template_blue.gif');
	$image    = imagecreatefromstring(base64_decode(substr($tab, strlen('data:image/gif;base64,'))));
	$changed  = 0;

	for ($x = 0; $x < imagesx($image); $x++) {
		for ($y = 0; $y < imagesy($image); $y++) {
			$drawn    = imagecolorsforindex($image, imagecolorat($image, $x, $y));
			$original = imagecolorsforindex($template, imagecolorat($template, $x, $y));

			if ($drawn['red'] > 200 && $drawn['green'] > 200 && $drawn['blue'] > 200 && $original['red'] < 200) {
				$changed++;
			}
		}
	}

	expect($changed)->toBeGreaterThan(20);
})->skip(!function_exists('imagettftext'), 'GD has no FreeType support');
