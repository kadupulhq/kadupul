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
 * include/.htaccess is always active, so it alone must deny vendor files and
 * the bootstrap files with their editor backups. The rules have to test the
 * file Apache mapped, because the request URI can carry repeated slashes
 * that never reach the file name. Apache and PHP both use PCRE, so the
 * patterns are lifted from the shipped files and run here unchanged.
 */

$root = dirname(__DIR__, 4);

function include_htaccess_text(string $file) : string {
	$lines = array();

	foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
		if (!preg_match('/^\s*#/', $line)) {
			$lines[] = $line;
		}
	}

	return implode("\n", $lines);
}

function include_htaccess_files_match(string $text) : string {
	expect(preg_match('/<FilesMatch "([^"]+)">/', $text, $m))->toBe(1);

	return '~' . $m[1] . '~';
}

/*
 * The Apache 2.4 vendor <If> reads "vendor path and not an allowed type, or a
 * vendor name with a PHP extension before another one". Both groupings of
 * && and || deny the same set, because the last clause names vendor too.
 */
function include_htaccess_denied_on_24(string $text, string $file) : bool {
	$expr = '/<If "%\{REQUEST_FILENAME\} =~ m#([^#]+)#i && ! %\{REQUEST_FILENAME\} =~ m#([^#]+)#i \|\| %\{REQUEST_FILENAME\} =~ m#([^#]+)#i">\s*Require all denied\s*<\/If>/';

	expect(preg_match($expr, $text, $m))->toBe(1);

	return (preg_match('#' . $m[1] . '#i', $file) === 1 && preg_match('#' . $m[2] . '#i', $file) !== 1)
		|| preg_match('#' . $m[3] . '#i', $file) === 1;
}

/* The Apache 2.2 rule sees the path below include/ and the conditions, joined by OR, the mapped file. */
function include_htaccess_denied_on_22(string $text, string $file) : bool {
	$expr = '/RewriteCond %\{REQUEST_FILENAME\} !(\S+) \[NC,OR\]\s*RewriteCond %\{REQUEST_FILENAME\} (\S+) \[NC\]\s*RewriteRule (\S+) - \[F,NC\]/';

	expect(preg_match($expr, $text, $m))->toBe(1);

	$relative = preg_replace('#^/var/www/kadupul/include/#', '', $file);

	return preg_match('#' . $m[3] . '#i', $relative) === 1
		&& (preg_match('#' . $m[1] . '#i', $file) !== 1 || preg_match('#' . $m[2] . '#i', $file) === 1);
}

dataset('bootstrap_denied', array(
	'config.php', 'global.php', 'plugins.php', 'CONFIG.PHP', 'config.php.dist',
	'config.php.bak', 'config.php.old', 'config.php~', '.config.php.swp',
	'.config.php.swo', '#config.php#', '.#config.php', '.global.php.swp',
	'#plugins.php#',
));

dataset('bootstrap_served', array(
	'global_arrays.php', 'layout.js', 'csrf.php', 'main.css', 'config.js',
));

dataset('vendor_denied', array(
	'/var/www/kadupul/include/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
	'/var/www/kadupul/include//vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
	'/var/www/kadupul/include/VENDOR/phpunit/phpunit/src/Util/PHP/EVAL-STDIN.PHP',
	'/var/www/kadupul/include/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php::$DATA',
	'/var/www/kadupul/include/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php.',
	'/var/www/kadupul/include/vendor/csrf/x.phtml',
	'/var/www/kadupul/include/vendor/csrf/README.md',
	'/var/www/kadupul/include/vendor/composer/installed.json',
	'/var/www/kadupul/include/vendor/csrf/x.php.css',
	'/var/www/kadupul/include//vendor/csrf/x.php.css',
	'/var/www/kadupul/include/vendor/csrf/x.phtml.js',
	'/var/www/kadupul/include/vendor/csrf/x.phar.png',
	'/var/www/kadupul/include/vendor/csrf/X.PHP.CSS',
	'/var/www/kadupul/include/vendor/csrf/X.PHTML.JS',
	'/var/www/kadupul/include/vendor/csrf/x.Phar.svg',
	'/var/www/kadupul/include/vendor/',
	'/var/www/kadupul/include/vendor',
));

dataset('vendor_served', array(
	'/var/www/kadupul/include/vendor/csrf/csrf-magic.js',
	'/var/www/kadupul/include/vendor/flag-icons/css/flag-icons.css',
	'/var/www/kadupul/include/vendor/flag-icons/flags/4x3/se.svg',
	'/var/www/kadupul/include/themes/modern/main.css',
	'/var/www/kadupul/include/global_arrays.php',
));

test('include/.htaccess has no rule on the request URI', function () use ($root) {
	expect(include_htaccess_text($root . '/include/.htaccess'))->not->toContain('REQUEST_URI');
});

test('include/.htaccess denies the bootstrap file or backup', function (string $name) use ($root) {
	$pattern = include_htaccess_files_match(include_htaccess_text($root . '/include/.htaccess'));

	expect(preg_match($pattern, $name))->toBe(1);
})->with('bootstrap_denied');

test('include/.htaccess leaves the include/ file to the server', function (string $name) use ($root) {
	$pattern = include_htaccess_files_match(include_htaccess_text($root . '/include/.htaccess'));

	expect(preg_match($pattern, $name))->toBe(0);
})->with('bootstrap_served');

test('include/.htaccess denies the mapped vendor file under Apache 2.4', function (string $file) use ($root) {
	expect(include_htaccess_denied_on_24(include_htaccess_text($root . '/include/.htaccess'), $file))->toBeTrue();
})->with('vendor_denied');

test('include/.htaccess serves the mapped file under Apache 2.4', function (string $file) use ($root) {
	expect(include_htaccess_denied_on_24(include_htaccess_text($root . '/include/.htaccess'), $file))->toBeFalse();
})->with('vendor_served');

test('include/.htaccess denies the mapped vendor file under Apache 2.2', function (string $file) use ($root) {
	expect(include_htaccess_denied_on_22(include_htaccess_text($root . '/include/.htaccess'), $file))->toBeTrue();
})->with('vendor_denied');

test('include/.htaccess serves the mapped file under Apache 2.2', function (string $file) use ($root) {
	expect(include_htaccess_denied_on_22(include_htaccess_text($root . '/include/.htaccess'), $file))->toBeFalse();
})->with('vendor_served');

test('.htaccess.dist denies every PHPUnit configuration name', function (string $name) use ($root) {
	$pattern = include_htaccess_files_match(include_htaccess_text($root . '/.htaccess.dist'));

	expect(preg_match($pattern, $name))->toBe(1);
})->with(array('phpunit.xml', 'phpunit.xml.dist', 'phpunit-audit.xml', 'PHPUNIT.XML.DIST'));
