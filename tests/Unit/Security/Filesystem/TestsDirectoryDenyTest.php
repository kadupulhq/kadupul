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
 * tests/ ships inside the web root on git-clone and git-archive installs and
 * holds PHP fixtures that were never meant to answer HTTP requests. Its
 * .htaccess must deny every request under both Apache 2.4 and 2.2, in the
 * same form cli/.htaccess uses.
 */

$root = dirname(__DIR__, 4);

function tests_htaccess_directives(string $file) : array {
	$lines = array();

	foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
		$line = trim($line);

		if ($line !== '' && $line[0] !== '#') {
			$lines[] = preg_replace('/\s+/', ' ', $line);
		}
	}

	return $lines;
}

test('tests/.htaccess exists', function () use ($root) {
	expect(is_file($root . '/tests/.htaccess'))->toBeTrue();
});

test('tests/.htaccess denies every request under Apache 2.4 and 2.2', function () use ($root) {
	expect(tests_htaccess_directives($root . '/tests/.htaccess'))->toBe(array(
		'<IfModule mod_authz_core.c>',
		'Require all denied',
		'</IfModule>',
		'<IfModule !mod_authz_core.c>',
		'Order Allow,Deny',
		'Deny from all',
		'</IfModule>',
	));
});

test('tests/.htaccess applies the same rules as cli/.htaccess', function () use ($root) {
	expect(tests_htaccess_directives($root . '/tests/.htaccess'))
		->toBe(tests_htaccess_directives($root . '/cli/.htaccess'));
});
