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
 * PHP casts a float to a string with 14 significant digits, so a microtime(true)
 * within 50 microseconds of a whole second loses its fraction, and 'U.u' then
 * refuses it. The settings table hands the same digits back as a string.
 */

$root            = dirname(__DIR__, 3);
$installerSource = file_get_contents($root . '/lib/installer.php');

if ($installerSource === false) {
	throw new RuntimeException('Unable to read installer source');
}

test('installer timestamps parse through the microtime helper', function () use ($installerSource) {
	expect($installerSource)->not->toContain("DateTime::createFromFormat('U.u', \$background");
});

test('microtime values without a fraction still produce a date', function () use ($root) {
	$stub = <<<'PHP'
<?php
$install_options = array();

function __($message) {
	$args = func_get_args();
	array_shift($args);

	return count($args) ? vsprintf($message, $args) : $message;
}

function cacti_sizeof($value) {
	return is_countable($value) ? count($value) : 0;
}

function read_config_option($name, $force = false) {
	global $install_options;

	return isset($install_options[$name]) ? $install_options[$name] : '';
}

function set_install_config_option($name, $value) {
	global $install_options;

	$install_options[$name] = $value;
}

function log_install_always($key, $message) {
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

require $argv[1] . '/lib/installer.php';

$parse = new ReflectionMethod('Installer', 'dateFromMicrotime');
$parse->setAccessible(true);

$format = function ($value) use ($parse) {
	$date = $parse->invoke(null, $value);

	return $date === false ? false : $date->format('Y-m-d H:i:s.u');
};

// The settings table returns the value as the string PHP cast it to
set_install_config_option('install_started', 1789333661.0);
$stored = (string) read_config_option('install_started', true);

$results = array(
	'whole float'     => $format(1789333661.0),
	'rounded float'   => $format(1789333661.99996),
	'stored whole'    => $format($stored),
	'stored fraction' => $format('1789333661.1235'),
	'stored 7 digits' => $format('1789333661.1234567'),
	'empty setting'   => $format(''),
	'background flag' => $format('-b'),
);

// Every value the old parse accepted must format exactly as it did before
$changed = 0;
for ($i = 0; $i < 20000; $i++) {
	$value = 1789333661 + $i / 20000;
	$old   = DateTime::createFromFormat('U.u', $value);

	if ($old !== false && $old->format('Y-m-d H:i:s.u') !== $format($value)) {
		$changed++;
	}
}

$results['changed'] = $changed;

print json_encode($results);
PHP;

	$script = tempnam(sys_get_temp_dir(), 'cacti_install_mt_');
	file_put_contents($script, $stub);

	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' .
		escapeshellarg($root) . ' 2>&1';

	$output = array();
	exec($cmd, $output);
	@unlink($script);

	$results = json_decode(implode("\n", $output), true);

	expect($results)->toBe(array(
		'whole float'     => '2026-09-13 21:07:41.000000',
		'rounded float'   => '2026-09-13 21:07:41.999960',
		'stored whole'    => '2026-09-13 21:07:41.000000',
		'stored fraction' => '2026-09-13 21:07:41.123500',
		'stored 7 digits' => '2026-09-13 21:07:41.123457',
		'empty setting'   => false,
		'background flag' => false,
		'changed'         => 0,
	));
});
