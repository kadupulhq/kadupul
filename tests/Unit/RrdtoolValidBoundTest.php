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
*/

/*
 * rrdtool_function_create() puts the stored, untrimmed bound into the DS
 * definition, so a control character anywhere in it must be refused, not
 * trimmed away. Plain surrounding spaces stay accepted.
 */

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');

foreach (array('cacti_has_control_chars', 'cacti_rrdtool_valid_bound') as $name) {
	if (!function_exists($name)) {
		preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match);
		eval($match[0]);
	}
}

test('refuses a bound with a leading or trailing control character', function () {
	foreach (array("\n1", "\r\n1", "\t1", "\x0B1", "\0" . '1', "1\n", "U\n", "\nU", "1\nquit\n") as $bound) {
		expect(cacti_rrdtool_valid_bound($bound))->toBeFalse();
	}
});

test('accepts numeric bounds and U', function () {
	foreach (array('U', '0', '-1', '1.5', '.5', '5.', '1.23e+10', '-4E-3', 100) as $bound) {
		expect(cacti_rrdtool_valid_bound($bound))->toBeTrue();
	}
});

test('accepts a bound with plain surrounding spaces', function () {
	expect(cacti_rrdtool_valid_bound(' 1 '))->toBeTrue()
		->and(cacti_rrdtool_valid_bound('  U'))->toBeTrue();
});

test('refuses a bound that is not a number', function () {
	foreach (array('', ' ', '1 2', 'abc', '1:2', 'UU') as $bound) {
		expect(cacti_rrdtool_valid_bound($bound))->toBeFalse();
	}
});
