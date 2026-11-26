<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * import_validate_signature() reports a strict boolean and is enforced at the
 * top of import_package(), so a Package whose key Cacti does not trust cannot be
 * imported (GHSA-274c-97hj-pv2v). Returning a populated array would let
 * `if (!import_validate_signature(...))` read a failed check as a pass.
 */

$src = file_get_contents(dirname(__DIR__, 2) . '/lib/import.php');

test('import_validate_signature is declared to return bool', function () use ($src) {
	expect($src)->toContain('function import_validate_signature($xmlfile) : bool {');
	expect($src)->toContain('is_cacti_public_key(trim((string) $data[\'public_key\']))');
});

test('a package with no key is not trusted (no default-key substitution)', function () use ($src) {
	$start = strpos($src, 'function import_validate_signature(');
	$body  = substr($src, $start, strpos($src, "\n}", $start) - $start);

	// must read the raw details, not import_package_get_public_key() (which
	// substitutes Cacti's official key when <publickey> is absent)
	expect($body)->toContain('import_package_get_details($xmlfile)');
	expect($body)->not->toContain('import_package_get_public_key(');
	// an absent or empty key returns false
	expect($body)->toContain("!isset(\$data['public_key']) || trim((string) \$data['public_key']) === ''");
});

test('import_package enforces the signature before reading the package', function () use ($src) {
	$guard = strpos($src, 'if (!import_validate_signature($xmlfile))');
	$read  = strpos($src, '$data = import_read_package_data($xmlfile, $public_key);');

	expect($guard)->not->toBeFalse();
	expect($read)->not->toBeFalse();
	expect($guard)->toBeLessThan($read);
});

test('import_validate_signature rejects a keyless package at runtime and does not trust the legacy 512-bit key', function () {
	$root = dirname(__DIR__, 2);
	$pkg  = tempnam(sys_get_temp_dir(), 'keyless-pkg-');
	$xml  = '<?xml version="1.0" encoding="UTF-8"?><xml><info><name>Unsigned</name></info></xml>';
	file_put_contents($pkg, gzencode($xml));

	$program = <<<'PHP'
function cacti_log($msg, ...$args) {}
function cacti_sizeof($a) { return is_array($a) ? count($a) : 0; }
function db_table_exists($t) { return false; }
require $argv[1] . '/lib/import.php';
$details = import_package_get_details($argv[2]);
$valid   = import_validate_signature($argv[2]);
$legacy  = is_cacti_public_key(get_public_key());
$sha256  = is_cacti_public_key(get_public_key_sha256());
echo json_encode(array(
	'public_key' => $details['public_key'] ?? null,
	'valid'      => $valid,
	'legacy'     => $legacy,
	'sha256'     => $sha256,
));
PHP;

	try {
		$proc = proc_open(array(PHP_BINARY, '-r', $program, $root, $pkg), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		expect(proc_close($proc))->toBe(0, $stderr)->and($stderr)->toBe('');
		$res = json_decode($stdout, true);
		expect($res['public_key'])->toBe('')
			->and($res['valid'])->toBeFalse()
			->and($res['legacy'])->toBeFalse()
			->and($res['sha256'])->toBeTrue();
	} finally {
		@unlink($pkg);
	}
});
