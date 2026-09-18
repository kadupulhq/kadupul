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
 * import_package() takes the destination of a package file from the package
 * itself. The name must start in scripts/ or resource/, at the Cacti base or
 * in a plugin, and no component may be a symlink that moves the write or the
 * preview read out of the Cacti tree. The real function runs against a temp
 * directory; only signature checks, logging and XML import are stubbed.
 */

$root = dirname(__DIR__, 2);

foreach (array('POLLER_VERBOSITY_LOW' => 2, 'POLLER_VERBOSITY_MEDIUM' => 3, 'OPENSSL_ALGO_SHA1' => 1, 'OPENSSL_ALGO_SHA256' => 7) as $name => $value) {
	if (!defined($name)) {
		define($name, $value);
	}
}

function importPkgDest_import_validate_signature($xmlfile) {
	return true;
}

function importPkgDest_import_read_package_data($xmlfile, &$public_key) {
	$public_key = str_repeat('k', 300);

	return $GLOBALS['import_pkg_dest']['data'];
}

function importPkgDest_openssl_verify($data, $signature, $key, $algo) {
	return 1;
}

function importPkgDest_cacti_log($message) {
	$GLOBALS['import_pkg_dest']['logs'][] = $message;
}

function importPkgDest___($text) {
	return $text;
}

function importPkgDest_cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

/* the realm decision is not what this test checks */
function importPkgDest_import_data_input_realm_allowed() {
	return true;
}

function importPkgDest_import_xml_data($xml) {
	$GLOBALS['import_pkg_dest']['xml'][] = $xml;

	return array();
}

function importPkgDestLoad($root) {
	if (function_exists('importPkgDest_import_package')) {
		return;
	}

	$functions = file_get_contents($root . '/lib/functions.php');

	foreach (array('validate_relative_path_within', 'cacti_path_is_within') as $name) {
		if (!function_exists($name)) {
			preg_match('/^function ' . $name . '\(.*?^}\n/ms', $functions, $match);
			eval($match[0]);
		}
	}

	$source = file_get_contents($root . '/lib/import.php');
	$start  = strpos($source, 'function import_package(');
	$end    = strpos($source, "\nfunction ", $start + 1);

	expect($start)->not->toBeFalse()
		->and($end)->not->toBeFalse();

	eval(preg_replace('/\b(import_package|import_validate_signature|import_read_package_data|openssl_verify|cacti_log|__|cacti_sizeof|import_xml_data|import_data_input_realm_allowed)\(/', 'importPkgDest_$1(', substr($source, $start, $end - $start)));
}

function importPkgDestNormalizeSelectedFile($root, $pfile) {
	if (!function_exists('package_import_normalize_selected_file')) {
		preg_match('/^function package_import_normalize_selected_file\(.*?^}\n/ms', file_get_contents($root . '/package_import.php'), $match);
		eval($match[0]);
	}

	return package_import_normalize_selected_file($pfile);
}

function importPkgDestRun($names, $preview = false, $import_files = array()) {
	$files = array();

	foreach ($names as $name) {
		$files[] = array('name' => $name, 'data' => base64_encode('payload for ' . $name), 'filesignature' => '');
	}

	/* every real package carries its template, and import_package() needs one */
	$files[] = array('name' => 'Test_Template.xml', 'data' => base64_encode('<template/>'), 'filesignature' => '');

	$GLOBALS['import_pkg_dest']['data'] = array('info' => array(), 'files' => array('file' => $files));

	return importPkgDest_import_package('package.xml.gz', 1, false, false, $preview, false, false, array(), $import_files);
}

beforeEach(function () use ($root) {
	importPkgDestLoad($root);

	$GLOBALS['import_pkg_dest'] = array('logs' => array(), 'xml' => array());

	$this->saved_config = isset($GLOBALS['config']) ? $GLOBALS['config'] : null;
	$this->tmp          = realpath(sys_get_temp_dir()) . '/import-pkg-dest-' . bin2hex(random_bytes(4));
	$this->base         = $this->tmp . '/cacti';
	$this->outside      = $this->tmp . '/outside';

	foreach (array('/scripts', '/resource/script_server', '/plugins/thold/scripts', '/plugins/thold/resource', '/evil/scripts') as $dir) {
		mkdir($this->base . $dir, 0700, true);
	}

	mkdir($this->outside, 0700);
	file_put_contents($this->outside . '/secret.txt', 'secret');

	$GLOBALS['config']['base_path'] = $this->base;
});

afterEach(function () {
	$GLOBALS['config'] = $this->saved_config;

	exec('rm -rf ' . escapeshellarg($this->tmp));
});

test('writes script and resource files at the Cacti base and in a plugin', function () {
	$names = array('scripts/ss_test.php', 'resource/script_server/test.xml', 'plugins/thold/scripts/t.php', 'plugins/thold/resource/t.xml');

	$result = importPkgDestRun($names);

	foreach ($names as $name) {
		expect(file_get_contents($this->base . '/' . $name))->toBe('payload for ' . $name)
			->and($result[1][$name])->toBe('written');
	}
});

test('previews an existing script file', function () {
	file_put_contents($this->base . '/scripts/ss_test.php', 'payload for scripts/ss_test.php');

	$result = importPkgDestRun(array('scripts/ss_test.php'), true);

	expect($result[1])->toBe(array('scripts/ss_test.php' => 'writable, identical'));
});

test('refuses a scripts directory that is not at the Cacti base or in a plugin', function () {
	$result = importPkgDestRun(array('evil/scripts/payload.php', 'plugins/thold/evil/resource/x.php'));

	expect(file_exists($this->base . '/evil/scripts/payload.php'))->toBeFalse()
		->and($result[1])->toBe(array())
		->and($GLOBALS['import_pkg_dest']['xml'])->toBe(array('<template/>'));
});

test('refuses a write through a symlinked directory', function () {
	expect(symlink($this->outside, $this->base . '/scripts/link'))->toBeTrue();

	$result = importPkgDestRun(array('scripts/link/payload.php'));

	expect(file_exists($this->outside . '/payload.php'))->toBeFalse()
		->and($result[1])->toBe(array());
});

test('refuses a write through a dangling symlink', function () {
	expect(symlink($this->outside . '/created.php', $this->base . '/scripts/dangling.php'))->toBeTrue();

	$result = importPkgDestRun(array('scripts/dangling.php'));

	expect(file_exists($this->outside . '/created.php'))->toBeFalse()
		->and($result[1])->toBe(array());
});

test('refuses to preview a file behind a symlink', function () {
	expect(symlink($this->outside . '/secret.txt', $this->base . '/resource/secret.xml'))->toBeTrue();

	$result = importPkgDestRun(array('resource/secret.xml'), true);

	expect($result[1])->toBe(array());
});

test('round-trips selected files when the base path is a symlink', function () use ($root) {
	/* packaged installs often reach the tree through a symlink, e.g.
	 * /var/www/html/cacti -> /usr/share/cacti */
	$link = $this->tmp . '/cacti-link';
	expect(symlink($this->base, $link))->toBeTrue();
	$GLOBALS['config']['base_path'] = $link;

	file_put_contents($this->base . '/scripts/changed.php', 'old');
	$names = array('scripts/changed.php', 'plugins/thold/resource/t.xml', 'resource/script_server/skipped.xml');

	$preview = importPkgDestRun($names, true);

	/* the preview keys come back as checkbox values and diff URLs */
	$selected = array();

	foreach (array_keys($preview[1]) as $pfile) {
		expect(validate_relative_path_within(str_replace($link . '/', '', $pfile), $link))->not->toBeFalse();

		if (strpos($pfile, 'skipped') === false) {
			$selected[] = importPkgDestNormalizeSelectedFile($root, $pfile);
		}
	}

	$result = importPkgDestRun($names, false, $selected);

	expect(file_get_contents($this->base . '/scripts/changed.php'))->toBe('payload for scripts/changed.php')
		->and(file_get_contents($this->base . '/plugins/thold/resource/t.xml'))->toBe('payload for plugins/thold/resource/t.xml')
		->and(file_exists($this->base . '/resource/script_server/skipped.xml'))->toBeFalse()
		->and($result[1])->toBe(array('scripts/changed.php' => 'written', 'plugins/thold/resource/t.xml' => 'written'));
});
