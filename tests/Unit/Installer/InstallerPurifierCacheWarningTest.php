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
 * 1.2.31 did not check cache/purifier, and HTMLPurifier runs without its
 * definition cache when the directory is not writable. The permission check
 * that the web and CLI installers share warns about it instead of stopping the
 * install or upgrade. Other required paths still stop it.
 */

function installer_purifier_permissions(array $unwritable) : array {
	$stub = <<<'PHP'
<?php
$logged = array();

function __($message) {
	$args = func_get_args();
	array_shift($args);

	return count($args) ? vsprintf($message, $args) : $message;
}

function cacti_sizeof($value) {
	return is_countable($value) ? count($value) : 0;
}

function is_resource_writable($path) {
	foreach ($GLOBALS['unwritable'] as $name) {
		if (strpos($path, '/cache/' . $name) !== false) {
			return false;
		}
	}

	return true;
}

function log_install_always($key, $message) {
	$GLOBALS['logged'][] = $message;
}

function log_install_high($key, $message) {
}

function log_install_medium($key, $message) {
}

function log_install_debug($section, $text, $background = false) {
}

function clean_up_lines($string) {
	return $string;
}

$config     = array('base_path' => '/cacti');
$unwritable = json_decode($argv[2], true);

require $argv[1] . '/lib/installer.php';

$installer = (new ReflectionClass('Installer'))->newInstanceWithoutConstructor();

foreach (array('mode' => Installer::MODE_UPGRADE, 'stepError' => false, 'errors' => array()) as $name => $value) {
	$property = new ReflectionProperty('Installer', $name);
	if (PHP_VERSION_ID < 80100) {
		$property->setAccessible(true);
	}
	$property->setValue($installer, $value);
}

$method = new ReflectionMethod('Installer', 'getPermissions');
if (PHP_VERSION_ID < 80100) {
	$method->setAccessible(true);
}
$permissions = $method->invoke($installer);

$errors = new ReflectionProperty('Installer', 'errors');
if (PHP_VERSION_ID < 80100) {
	$errors->setAccessible(true);
}
$step = new ReflectionProperty('Installer', 'stepError');
if (PHP_VERSION_ID < 80100) {
	$step->setAccessible(true);
}

print json_encode(array(
	'errors'    => $errors->getValue($installer),
	'stepError' => $step->getValue($installer),
	'purifier'  => $permissions['always']['/cacti/cache/purifier/'],
	'logged'    => $logged,
));
PHP;

	$script = tempnam(sys_get_temp_dir(), 'kadupul_purifier_');
	file_put_contents($script, $stub);

	$output = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(dirname(__DIR__, 3)) . ' ' . escapeshellarg(json_encode($unwritable)) . ' 2>&1', $output);
	@unlink($script);

	$result = json_decode(implode("\n", $output), true);

	if (!is_array($result)) {
		throw new RuntimeException('Installer permission probe failed: ' . implode("\n", $output));
	}

	return $result;
}

test('an unwritable purifier cache logs a warning and adds no installer error', function () {
	$result = installer_purifier_permissions(array('purifier'));

	expect($result['errors'])->toBe(array())
		->and($result['stepError'])->toBeFalse()
		->and($result['purifier'])->toBeFalse()
		->and($result['logged'])->toBe(array('WARNING: Path is not writable, HTMLPurifier will run without a definition cache: /cacti/cache/purifier/'));
});

test('other unwritable cache paths still stop the installer', function () {
	$result = installer_purifier_permissions(array('boost', 'purifier'));

	expect($result['errors'])->toBe(array('Permission' => array('boost:/cacti/cache/boost/' => 'Path is not writable')))
		->and($result['stepError'])->toBe(4);
});

test('a writable purifier cache produces no warning', function () {
	$result = installer_purifier_permissions(array());

	expect($result['errors'])->toBe(array())
		->and($result['purifier'])->toBeTrue()
		->and($result['logged'])->toBe(array());
});
