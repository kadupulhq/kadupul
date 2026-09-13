<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 +-------------------------------------------------------------------------+
*/

$root            = dirname(__DIR__, 3);
$installerSource = file_get_contents($root . '/lib/installer.php');
$cliSource       = file_get_contents($root . '/cli/install_cacti.php');

if ($installerSource === false || $cliSource === false) {
	throw new RuntimeException('Unable to read installer sources');
}

test('installer reports missing core tables without failing the upgrade', function () use ($installerSource) {
	$validation = strpos($installerSource, '$schema_warning = $this->validateCoreSchema();');
	$version    = strpos($installerSource, "db_execute('TRUNCATE TABLE version');");
	$complete   = strpos($installerSource, '$this->setStep(Installer::STEP_COMPLETE);');

	expect($validation)->not->toBeFalse()
		->and($version)->not->toBeFalse()
		->and($complete)->not->toBeFalse();

	if ($validation === false || $version === false || $complete === false) {
		throw new RuntimeException('Unable to locate installer completion sequence');
	}

	expect($validation)->toBeLessThan($version)
		->and($validation)->toBeLessThan($complete)
		->and($installerSource)->not->toContain('$failure = $this->validateCoreSchema();')
		->and($installerSource)->toContain('WARNING: Core database tables are missing: %s')
		->and($installerSource)->toContain('WARNING: Unable to query the installed database schema');
});

test('background and CLI installation paths preserve failure state', function () use ($installerSource, $cliSource) {
	expect($installerSource)->toContain('$success = $installer->getStep() === Installer::STEP_COMPLETE;')
		->and($installerSource)->not->toContain("set_install_config_option('install_step', Installer::STEP_COMPLETE);")
		->and($installerSource)->toContain('catch (Throwable $e)')
		->and($cliSource)->toContain('$install_failed = !Installer::beginInstall($time, $installer);')
		->and($cliSource)->toContain('if ($install_failed || $installer->getStep() === Installer::STEP_ERROR) {');
});

test('an exception during install leaves the passed installer and saved step at the error step', function () {
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

// setDefaults() asks for the table list first, so this fails the install before any database work
function install_setup_get_tables() {
	throw new Exception('table list unavailable');
}

require $argv[1] . '/lib/installer.php';

$install_options = array(
	'install_eula'    => 'on',
	'install_started' => '',
	'install_step'    => Installer::STEP_INSTALL,
);

$installer = new class extends Installer {
	public function __construct() {
	}
};

$success = Installer::beginInstall('-b', $installer);

print json_encode(array($success, $installer->getStep(), (int) $install_options['install_step']));
PHP;

	$script = tempnam(sys_get_temp_dir(), 'cacti_install_exc_');
	file_put_contents($script, $stub);

	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' .
		escapeshellarg(dirname(__DIR__, 3)) . ' 2>&1';

	$output = array();
	exec($cmd, $output);
	@unlink($script);

	expect(implode("\n", $output))->toBe('[false,99,99]');
});
