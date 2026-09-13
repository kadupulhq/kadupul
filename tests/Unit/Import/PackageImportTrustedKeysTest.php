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

namespace PackageImportTrustedKeysTest;

/*
 * The web Package Import gate and file preview trust both official Cacti keys,
 * as 1.2.31 did through import_read_package_data(). No schema creates
 * package_public_keys, so it is read only when it exists. Signature checks are
 * unchanged: a tampered, unsigned or self-signed package is still refused.
 */

if (!defined('POLLER_VERBOSITY_LOW')) {
	define('POLLER_VERBOSITY_LOW', 2);
}

if (!defined('POLLER_VERBOSITY_MEDIUM')) {
	define('POLLER_VERBOSITY_MEDIUM', 3);
}

function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') {
	$GLOBALS['trusted_keys_log'][] = $message;
}

function cacti_sizeof($value) {
	return is_array($value) ? count($value) : 0;
}

function __($text) {
	return $text;
}

function array_rekey($array, $key, $key_value) {
	$out = array();

	foreach ((array) $array as $row) {
		$out[$row[$key]] = $row[$key_value];
	}

	return $out;
}

function db_table_exists($table, $log = true, $db_conn = false) {
	return $GLOBALS['trusted_keys_table'] !== null;
}

function db_fetch_assoc($sql) {
	$GLOBALS['trusted_keys_queries'][] = $sql;

	return $GLOBALS['trusted_keys_table'] === null ? false : $GLOBALS['trusted_keys_table'];
}

$root   = dirname(__DIR__, 3);
$import = file_get_contents($root . '/lib/import.php');
$page   = file_get_contents($root . '/package_import.php');
$code   = '';

foreach (array(
	array($import, 'is_cacti_public_key'),
	array($import, 'get_public_key_sha1'),
	array($import, 'get_public_key_sha256'),
	array($import, 'get_public_key'),
	array($import, 'import_package_get_public_key'),
	array($import, 'import_package_get_details'),
	array($import, 'import_read_package_data'),
	array($import, 'xml_to_array'),
	array($page, 'package_public_key_is_trusted'),
	array($page, 'package_validate_signature'),
	array($page, 'package_import_write_session_file'),
	array($page, 'package_file_get_contents'),
) as $wanted) {
	if (preg_match('/^function ' . $wanted[1] . '\(.*?^}\R/ms', $wanted[0], $match) !== 1) {
		throw new \RuntimeException('Unable to extract ' . $wanted[1] . '()');
	}

	$code .= $match[0];
}

eval('namespace PackageImportTrustedKeysTest;' . $code); // nosemgrep: php.lang.security.eval-use.eval-use

function trusted_keys_fixture(string $xml, string $label) : string {
	$path = sys_get_temp_dir() . '/kadupul-trusted-keys-' . $label . '-' . bin2hex(random_bytes(4)) . '.xml.gz';

	file_put_contents('compress.zlib://' . $path, $xml);

	return $path;
}

function trusted_keys_self_signed(string $xml) : string {
	$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
	$pub = openssl_pkey_get_details($key)['key'];
	$xml = preg_replace('#<publickey>.*?</publickey>#s', '<publickey>' . base64_encode($pub) . '</publickey>', $xml);
	$xml = preg_replace_callback('#<data>(.*?)</data>(\s*)<filesignature>.*?</filesignature>#s', function ($m) use ($key) {
		openssl_sign(base64_decode($m[1]), $sig, $key, OPENSSL_ALGO_SHA256);

		return '<data>' . $m[1] . '</data>' . $m[2] . '<filesignature>' . base64_encode($sig) . '</filesignature>';
	}, $xml);
	$xml = preg_replace('#^\s*<signature>.*?</signature>$#m', '   <signature></signature>', $xml);

	openssl_sign($xml, $sig, $key, OPENSSL_ALGO_SHA256);

	return str_replace('   <signature></signature>', '   <signature>' . base64_encode($sig) . '</signature>', $xml);
}

beforeEach(function () {
	$GLOBALS['trusted_keys_log']     = array();
	$GLOBALS['trusted_keys_queries'] = array();
	$GLOBALS['trusted_keys_table']   = null;
	$_SESSION                        = array();
	$this->packages                  = glob(dirname(__DIR__, 3) . '/install/templates/*.xml.gz');
});

test('every shipped package passes the web import gate and verifies', function () {
	expect($this->packages)->toHaveCount(30);

	foreach ($this->packages as $package) {
		$signature = null;

		expect(package_validate_signature($package))->toBeTrue(basename($package))
			->and(import_read_package_data($package, $signature))->toBeArray(basename($package));
	}

	expect($GLOBALS['trusted_keys_queries'])->toBe(array());
});

test('the preview shows every file of every shipped package', function () {
	foreach ($this->packages as $package) {
		$_SESSION['sess_import_package'] = file_get_contents($package);

		preg_match_all('#<file>\s*<name>((?:scripts|resource)/[^<]+)</name>#', file_get_contents('compress.zlib://' . $package), $names);

		expect($names[1])->not->toBe(array());

		foreach ($names[1] as $name) {
			expect(package_file_get_contents($name))->not->toBeFalse(basename($package) . ': ' . $name);
		}
	}
});

test('an edited, unsigned or self-signed package is still refused', function () {
	$xml = file_get_contents('compress.zlib://' . dirname(__DIR__, 3) . '/install/templates/Local_Linux_Machine.xml.gz');

	$edited      = trusted_keys_fixture(str_replace('<author>The Cacti Group</author>', '<author>The Cacti Groop</author>', $xml), 'edited');
	$unsigned    = trusted_keys_fixture(preg_replace('#<signature>.*?</signature>#s', '<signature></signature>', $xml), 'unsigned');
	$self_signed = trusted_keys_fixture(trusted_keys_self_signed($xml), 'self');

	$signature = null;

	expect(import_read_package_data($edited, $signature))->toBeFalse()
		->and(import_read_package_data($unsigned, $signature))->toBeFalse()
		->and(package_validate_signature($self_signed))->toBeFalse()
		->and(import_read_package_data($self_signed, $signature))->toBeFalse();

	foreach (array($edited, $unsigned, $self_signed) as $path) {
		unlink($path);
	}
});

test('an operator trusted key table is still honoured when it exists', function () {
	$xml         = file_get_contents('compress.zlib://' . dirname(__DIR__, 3) . '/install/templates/Local_Linux_Machine.xml.gz');
	$self_signed = trusted_keys_fixture(trusted_keys_self_signed($xml), 'table');
	$key         = base64_decode(simplexml_load_string(file_get_contents('compress.zlib://' . $self_signed))->publickey);

	$GLOBALS['trusted_keys_table'] = array(array('public_key' => $key));

	expect(package_validate_signature($self_signed))->toBeTrue()
		->and(package_public_key_is_trusted($key))->toBeTrue()
		->and($GLOBALS['trusted_keys_queries'])->not->toBe(array());

	$GLOBALS['trusted_keys_table'] = array();

	expect(package_public_key_is_trusted($key))->toBeFalse()
		->and(package_public_key_is_trusted(''))->toBeFalse();

	unlink($self_signed);
});
