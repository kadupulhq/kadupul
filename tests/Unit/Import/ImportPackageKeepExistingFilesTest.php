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

namespace ImportPackageKeepExistingFilesTest;

/*
 * Shipped packages carry older copies of scripts the release has since fixed,
 * such as ss_cpoller.php before its prepared query. The installer and upgrade
 * import write a package file only when it is missing. An administrator's web
 * or CLI import still replaces files, as in 1.2.31, and logs a warning when the
 * content differs. Signature checks and XML import are stubbed.
 */

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 2);
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 3);
}

function import_validate_signature($xmlfile) {
	return true;
}

function import_read_package_data($xmlfile, &$public_key) {
	$public_key = str_repeat('k', 300);

	return $GLOBALS['keep_files']['data'];
}

function openssl_verify($data, $signature, $key, $algo) {
	return 1;
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['keep_files']['logs'][] = $message;
}

function __($text) {
	return $text;
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function import_xml_data($xml) {
	return array();
}

$root      = dirname(__DIR__, 3);
$functions = file_get_contents($root . '/lib/functions.php');
$import    = file_get_contents($root . '/lib/import.php');
$code      = '';

foreach (array(array($functions, 'validate_relative_path_within'), array($functions, 'cacti_path_is_within'), array($import, 'import_package')) as $wanted) {
	if (preg_match('/^function ' . $wanted[1] . '\(.*?^}\R/ms', $wanted[0], $match) !== 1) {
		throw new \RuntimeException('Unable to extract ' . $wanted[1] . '()');
	}

	$code .= $match[0];
}

eval('namespace ImportPackageKeepExistingFilesTest;' . $code); // nosemgrep: php.lang.security.eval-use.eval-use

function keep_files_run(array $files, bool $replace = true) {
	$entries = array();

	foreach ($files as $name => $content) {
		$entries[] = array('name' => $name, 'data' => base64_encode($content), 'filesignature' => '');
	}

	$entries[] = array('name' => 'Test_Template.xml', 'data' => base64_encode('<template/>'), 'filesignature' => '');

	$GLOBALS['keep_files']['data'] = array('info' => array(), 'files' => array('file' => $entries));

	if ($replace) {
		return import_package('package.xml.gz', 1, false, false, false, false, false);
	}

	return import_package('package.xml.gz', 1, false, false, false, false, false, array(), array(), '', false);
}

beforeEach(function () {
	$this->base = realpath(sys_get_temp_dir()) . '/kadupul-keep-files-' . bin2hex(random_bytes(4));

	mkdir($this->base . '/scripts', 0755, true);
	mkdir($this->base . '/resource/snmp_queries', 0755, true);

	$this->saved_config           = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$GLOBALS['config']['base_path'] = $this->base;
	$GLOBALS['keep_files']          = array('logs' => array());

	$this->hardened = "<?php\n\$stats = db_fetch_cell_prepared('SELECT value FROM settings WHERE name = ?', array('stats_recache_' . \$index));\n";
	$this->packaged = "<?php\n\$stats = db_fetch_cell('SELECT value FROM settings WHERE name=\"stats_recache_' . \$index . '\"');\n";

	file_put_contents($this->base . '/scripts/ss_cpoller.php', $this->hardened);
});

afterEach(function () {
	exec('rm -rf ' . escapeshellarg($this->base));

	$GLOBALS['config'] = $this->saved_config;
});

test('an installer import keeps the hardened script and installs a missing one', function () {
	keep_files_run(array(
		'scripts/ss_cpoller.php'                  => $this->packaged,
		'resource/snmp_queries/new_query.xml'     => '<query/>',
	), false);

	expect(file_get_contents($this->base . '/scripts/ss_cpoller.php'))->toBe($this->hardened)
		->and(file_get_contents($this->base . '/resource/snmp_queries/new_query.xml'))->toBe('<query/>')
		->and($GLOBALS['keep_files']['logs'])->toContain('NOTE: Keeping existing file: ' . $this->base . '/scripts/ss_cpoller.php')
		->and(implode("\n", $GLOBALS['keep_files']['logs']))->not->toContain('WARNING');
});

test('an administrator import still replaces the file and warns about the difference', function () {
	keep_files_run(array('scripts/ss_cpoller.php' => $this->packaged));

	expect(file_get_contents($this->base . '/scripts/ss_cpoller.php'))->toBe($this->packaged)
		->and($GLOBALS['keep_files']['logs'])->toContain('WARNING: Package file replaces a different existing file: ' . $this->base . '/scripts/ss_cpoller.php');
});

test('an administrator import of identical content writes without a warning', function () {
	keep_files_run(array('scripts/ss_cpoller.php' => $this->hardened));

	expect(file_get_contents($this->base . '/scripts/ss_cpoller.php'))->toBe($this->hardened)
		->and(implode("\n", $GLOBALS['keep_files']['logs']))->not->toContain('WARNING');
});

test('the installer imports shipped packages without replacing files', function () {
	$source = file_get_contents(dirname(__DIR__, 3) . '/lib/installer.php');

	expect($source)->toContain("import_package(\$path . \$package, \$this->profile, false, false, false, false, true, array(), array(), \$info['class'], false);");
});
