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
 * The web installer's Directory Permission Checks step disables Next for every
 * unwritable path. An unwritable cache/purifier only costs HTMLPurifier its
 * definition cache, so the step shows it as a warning and keeps Next enabled.
 * Any other unwritable path still blocks the step.
 */

function installer_permission_step(array $always, array $install = array()) : array {
	$stub = <<<'PHP'
<?php
foreach (array('DB_STATUS_ERROR' => 0, 'DB_STATUS_WARNING' => 1, 'DB_STATUS_RESTART' => 2, 'DB_STATUS_SUCCESS' => 3, 'DB_STATUS_SKIPPED' => 4) as $name => $value) {
	define($name, $value);
}

function __($message) {
	$args = func_get_args();
	array_shift($args);

	return count($args) ? vsprintf($message, $args) : $message;
}

function cacti_sizeof($value) {
	return is_countable($value) ? count($value) : 0;
}

function get_running_user() {
	return 'apache';
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

$config = array('base_path' => '/cacti', 'cacti_server_os' => 'unix');
$input  = json_decode($argv[2], true);

require $argv[1] . '/lib/installer.php';

$installer = (new ReflectionClass('Installer'))->newInstanceWithoutConstructor();

foreach (array(
	'mode'        => Installer::MODE_UPGRADE,
	'permissions' => array('install' => $input['install'], 'always' => $input['always']),
	'iconClass'   => array(DB_STATUS_ERROR => 'icon-error', DB_STATUS_WARNING => 'icon-warning', DB_STATUS_SUCCESS => 'icon-success'),
	'buttonNext'  => new InstallerButton(),
) as $name => $value) {
	$property = new ReflectionProperty('Installer', $name);
	$property->setAccessible(true);
	$property->setValue($installer, $value);
}

$output = $installer->processStepPermissionCheck();

$next = new ReflectionProperty('Installer', 'buttonNext');
$next->setAccessible(true);
$data = new ReflectionProperty('Installer', 'stepData');
$data->setAccessible(true);

print json_encode(array(
	'enabled'  => $next->getValue($installer)->Enabled,
	'sections' => $data->getValue($installer)['Sections'],
	'output'   => $output,
));
PHP;

	$script = tempnam(sys_get_temp_dir(), 'kadupul_permission_step_');
	file_put_contents($script, $stub);

	$lines = array();
	exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(dirname(__DIR__, 3)) . ' ' . escapeshellarg(json_encode(array('always' => $always, 'install' => $install))) . ' 2>&1', $lines);
	@unlink($script);

	$result = json_decode(implode("\n", $lines), true);

	if (!is_array($result)) {
		throw new RuntimeException('Permission step probe failed: ' . implode("\n", $lines));
	}

	return $result;
}

function installer_permission_paths(array $unwritable) : array {
	$paths = array();

	foreach (array('log', 'cache/boost', 'cache/mibcache', 'cache/purifier', 'cache/realtime', 'cache/spikekill') as $name) {
		$paths['/cacti/' . $name . '/'] = !in_array($name, $unwritable, true);
	}

	return $paths;
}

test('an unwritable purifier cache is a warning and Next stays enabled', function () {
	$result = installer_permission_step(installer_permission_paths(array('cache/purifier')));

	expect($result['enabled'])->toBeTrue()
		->and($result['sections']['writable_always'])->toBe(1)
		->and($result['sections']['host_access'])->toBe(3)
		->and($result['output'])->toContain("/cacti/cache/purifier/</div></div><div class='formColumnRight'><div class='formData' width='100%'><i class=\"icon-warning\"></i> <font color=\"orange\">Not Writable</font>")
		->and($result['output'])->toContain('All folders are writable');
});

test('another unwritable path still blocks the step', function () {
	$result = installer_permission_step(installer_permission_paths(array('cache/boost')));

	expect($result['enabled'])->toBeFalse()
		->and($result['sections']['writable_always'])->toBe(0)
		->and($result['sections']['host_access'])->toBe(1)
		->and($result['output'])->toContain('chown -R apache:apache /cacti/cache/boost/');
});

test('an unwritable purifier cache does not hide another blocking path', function () {
	$result = installer_permission_step(installer_permission_paths(array('cache/purifier', 'cache/boost')));

	expect($result['enabled'])->toBeFalse()
		->and($result['sections']['writable_always'])->toBe(0)
		->and($result['output'])->toContain('<i class="icon-error"></i> <font color="#FF0000">Not Writable</font>');
});

test('an unwritable install-time path still blocks the step', function () {
	$result = installer_permission_step(installer_permission_paths(array()), array('/cacti/scripts/' => false));

	expect($result['enabled'])->toBeFalse()
		->and($result['sections']['writable_install'])->toBe(0);
});

test('all writable paths keep Next enabled with success sections', function () {
	$result = installer_permission_step(installer_permission_paths(array()));

	expect($result['enabled'])->toBeTrue()
		->and($result['sections']['writable_always'])->toBe(3);
});
