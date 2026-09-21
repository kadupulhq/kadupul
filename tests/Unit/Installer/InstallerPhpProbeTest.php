<?php

namespace InstallerPhpProbeTest;

/* Execute the production method with configuration, logging and process
 * boundaries stubbed. The executor integration suite covers actual processes. */
$source = file_get_contents(dirname(__DIR__, 3) . '/lib/installer.php');
if (!preg_match('/\tprivate function setPaths\(.*?\n\t}\n/s', $source, $method)) {
	throw new \RuntimeException('Installer::setPaths not found');
}

eval('namespace InstallerPhpProbeTest; class Installer {
	const STEP_BINARY_LOCATIONS = 5;
	public $stepCurrent = 5;
	public $paths = array("path_php_binary" => array());
	public $errors = array();
	public function run($paths) { $this->setPaths($paths); }
	public function addError($step, $section, $name, $message) {
		$this->errors[$section][$name] = $message;
	}
' . $method[0] . '}');

function log_install_debug($section, $message) {}
function log_install_high($section, $message) {}
function cacti_count($items) { return count($items); }
function clean_up_lines($value) { return $value; }
function __($value) { return $value; }
function file_exists($path) { return $GLOBALS['probe']['exists']; }
function set_install_config_option($name, $value) {
	$GLOBALS['probe']['saved'][$name] = $value;
}
function cacti_exec($binary, $args, &$output, $timeout) {
	$GLOBALS['probe']['calls'][] = array($binary, $args, $timeout);
	$output = array($GLOBALS['probe']['correct'] ? (string) ((int) $args[2] ** 2) : 'incorrect');
	return $GLOBALS['probe']['status'];
}

test('installer PHP probe preserves argv and rejects failed probes', function ($status, $correct, $exists, $accepted) {
	global $config;
	$previous = $config ?? null;
	$config = array('base_path' => '/installation path;literal');
	$binary = '/operator selected/php;literal';
	$GLOBALS['probe'] = array(
		'status' => $status, 'correct' => $correct, 'exists' => $exists,
		'calls' => array(), 'saved' => array()
	);
	try {
		$installer = new Installer();
		$installer->run(array('path_php_binary' => $binary));
		$probe = $GLOBALS['probe'];
		expect($probe['saved'])->toBe($accepted ? array('path_php_binary' => $binary) : array());
		expect(isset($installer->errors['Paths']['path_php_binary']))->toBe(!$accepted);
		expect(count($probe['calls']))->toBe($exists ? 1 : 0);
		if ($exists) {
			list($actualBinary, $args, $timeout) = $probe['calls'][0];
			expect($actualBinary)->toBe($binary);
			expect(count($args))->toBe(3);
			expect($args[0])->toBe('-q');
			expect($args[1])->toBe('/installation path;literal/install/cli_test.php');
			expect(ctype_digit($args[2]))->toBeTrue();
			expect((int) $args[2])->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(64);
			expect($timeout)->toBeNull();
		}
	} finally {
		$config = $previous;
		unset($GLOBALS['probe']);
	}
})->with(array(
	'success' => array(0, true, true, true),
	'wrong output' => array(0, false, true, false),
	'nonzero status with correct output' => array(7, true, true, false),
	'spawn failure' => array(255, false, true, false),
	'missing executable' => array(0, true, false, false),
));
