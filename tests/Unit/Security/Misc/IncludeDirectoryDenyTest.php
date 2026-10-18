<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * include/.htaccess is always active, so it alone must deny vendor files and
 * the bootstrap files with their editor backups. The rules have to test the
 * file Apache mapped, because the request URI can carry repeated slashes
 * that never reach the file name. Apache and PHP both use PCRE, so the
 * patterns are lifted from the shipped files and run here unchanged.
 */

namespace IncludeDirectoryDenyTest;

const ROOT = __DIR__ . '/../../../..';
const PREFIX = '/var/www/kadupul/include/';

function rules(string $file): string
{
    $lines = file(ROOT . '/' . $file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new \RuntimeException('Unable to read ' . $file);
    }

    return implode("\n", preg_grep('/^\s*#/', $lines, PREG_GREP_INVERT));
}

function files_match(string $text): string
{
    expect(preg_match('/<FilesMatch "([^"]+)">/', $text, $m))->toBe(1);

    return '~' . $m[1] . '~';
}

/*
 * The Apache 2.4 vendor <If> reads "vendor path and not an allowed type, or a
 * vendor name with a PHP extension before another one". Both groupings of
 * && and || deny the same set, because the last clause names vendor too.
 */
function denied_on_24(string $file): bool
{
    $expr = '/<If "%\{REQUEST_FILENAME\} =~ m#([^#]+)#i && ! %\{REQUEST_FILENAME\} =~ m#([^#]+)#i \|\| %\{REQUEST_FILENAME\} =~ m#([^#]+)#i">\s*Require all denied\s*<\/If>/';
    expect(preg_match($expr, rules('include/.htaccess'), $m))->toBe(1);

    return (preg_match('#' . $m[1] . '#i', $file) === 1 && preg_match('#' . $m[2] . '#i', $file) !== 1)
        || preg_match('#' . $m[3] . '#i', $file) === 1;
}

/* The Apache 2.2 rule sees the path below include/ and the conditions, joined by OR, the mapped file. */
function denied_on_22(string $file): bool
{
    $expr = '/RewriteCond %\{REQUEST_FILENAME\} !(\S+) \[NC,OR\]\s*RewriteCond %\{REQUEST_FILENAME\} (\S+) \[NC\]\s*RewriteRule (\S+) - \[F,NC\]/';
    expect(preg_match($expr, rules('include/.htaccess'), $m))->toBe(1);

    $relative = substr($file, strlen(PREFIX));

    return preg_match('#' . $m[3] . '#i', $relative) === 1
        && (preg_match('#' . $m[1] . '#i', $file) !== 1 || preg_match('#' . $m[2] . '#i', $file) === 1);
}

$bootstrapDenied = [
    'config.php', 'global.php', 'plugins.php', 'CONFIG.PHP', 'config.php.dist',
    'config.php.bak', 'config.php.old', 'config.php~', '.config.php.swp',
    '.config.php.swo', '#config.php#', '.#config.php', '.global.php.swp',
    '#plugins.php#',
];

$bootstrapServed = ['global_arrays.php', 'layout.js', 'csrf.php', 'main.css', 'config.js'];

$vendorDenied = [
    PREFIX . 'vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
    '/var/www/kadupul/include//vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php',
    PREFIX . 'VENDOR/phpunit/phpunit/src/Util/PHP/EVAL-STDIN.PHP',
    PREFIX . 'vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php::$DATA',
    PREFIX . 'vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php.',
    PREFIX . 'vendor/csrf/x.phtml',
    PREFIX . 'vendor/csrf/README.md',
    PREFIX . 'vendor/composer/installed.json',
    PREFIX . 'vendor/csrf/x.php.css',
    '/var/www/kadupul/include//vendor/csrf/x.php.css',
    PREFIX . 'vendor/csrf/x.phtml.js',
    PREFIX . 'vendor/csrf/x.phar.png',
    PREFIX . 'vendor/csrf/X.PHP.CSS',
    PREFIX . 'vendor/csrf/X.PHTML.JS',
    PREFIX . 'vendor/csrf/x.Phar.svg',
    PREFIX . 'vendor/',
    PREFIX . 'vendor',
];

$vendorServed = [
    PREFIX . 'vendor/csrf/csrf-magic.js',
    PREFIX . 'vendor/flag-icons/css/flag-icons.css',
    PREFIX . 'vendor/flag-icons/flags/4x3/se.svg',
    PREFIX . 'themes/modern/main.css',
    PREFIX . 'global_arrays.php',
];

test('include/.htaccess has no rule on the request URI', function () {
    expect(rules('include/.htaccess'))->not->toContain('REQUEST_URI');
});

test('include/.htaccess denies the bootstrap file or backup', function (string $name) {
    expect(preg_match(files_match(rules('include/.htaccess')), $name))->toBe(1);
})->with($bootstrapDenied);

test('include/.htaccess leaves the include/ file to the server', function (string $name) {
    expect(preg_match(files_match(rules('include/.htaccess')), $name))->toBe(0);
})->with($bootstrapServed);

test('include/.htaccess denies the mapped vendor file under Apache 2.4', function (string $file) {
    expect(denied_on_24($file))->toBeTrue();
})->with($vendorDenied);

test('include/.htaccess serves the mapped file under Apache 2.4', function (string $file) {
    expect(denied_on_24($file))->toBeFalse();
})->with($vendorServed);

test('include/.htaccess denies the mapped vendor file under Apache 2.2', function (string $file) {
    expect(denied_on_22($file))->toBeTrue();
})->with($vendorDenied);

test('include/.htaccess serves the mapped file under Apache 2.2', function (string $file) {
    expect(denied_on_22($file))->toBeFalse();
})->with($vendorServed);

test('.htaccess.dist denies every PHPUnit configuration name', function (string $name) {
    expect(preg_match(files_match(rules('.htaccess.dist')), $name))->toBe(1);
})->with(['phpunit.xml', 'phpunit.xml.dist', 'phpunit-audit.xml', 'PHPUNIT.XML.DIST']);
