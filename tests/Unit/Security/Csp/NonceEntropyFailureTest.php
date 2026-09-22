<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

test('CSP nonce uses secure entropy or fails closed', function ($primary, $secondary, $success) {
	$namespace = 'NonceEntropyCase_' . $primary . '_' . $secondary;
	$source = file_get_contents(dirname(__DIR__, 4) . '/lib/headers_secure.php');
	$stubs = <<<'PHP'
function function_exists($name) {
	if ($name === 'random_bytes') return $GLOBALS['nonce_primary'] !== 'absent';
	if ($name === 'openssl_random_pseudo_bytes') return $GLOBALS['nonce_secondary'] !== 'absent';
	return false;
}
function random_bytes($length) {
	$GLOBALS['nonce_primary_calls']++;
	if ($GLOBALS['nonce_primary'] === 'throw') throw new \Exception('entropy unavailable');
	return str_repeat("\xfb", $length);
}
function openssl_random_pseudo_bytes($length, &$strong) {
	$GLOBALS['nonce_secondary_calls']++;
	$mode = $GLOBALS['nonce_secondary'];
	if ($mode === 'throw') throw new \Exception('OpenSSL unavailable');
	$strong = $mode !== 'weak';
	if ($mode === 'false') return false;
	return str_repeat("\xfb", $mode === 'short' ? 1 : $length);
}
PHP;
	$GLOBALS['nonce_primary'] = $primary;
	$GLOBALS['nonce_secondary'] = $secondary;
	$GLOBALS['nonce_primary_calls'] = 0;
	$GLOBALS['nonce_secondary_calls'] = 0;
	eval('namespace ' . $namespace . ';' . $stubs . substr($source, 5));
	$class = $namespace . '\\CactiSecureHeaders';
	if ($success) {
		$nonce = $class::getNonce();
		expect($nonce)->toMatch('/^[A-Za-z0-9_-]{24}$/');
		expect($class::getNonce())->toBe($nonce);
		expect($class::getNonceAttribute())->toBe('nonce="' . $nonce . '"');
		expect($GLOBALS['nonce_primary_calls'])->toBe($primary === 'absent' ? 0 : 1);
		expect($GLOBALS['nonce_secondary_calls'])->toBe($primary === 'success' ? 0 : 1);
	} else {
		expect(fn () => $class::getNonce())->toThrow(\RuntimeException::class, 'Unable to generate a secure CSP nonce');
		// Failed entropy must not cache an insecure value; a later healthy source works.
		$GLOBALS['nonce_primary'] = 'success';
		expect($class::getNonce())->toMatch('/^[A-Za-z0-9_-]{24}$/');
	}
})->with([
	['success', 'throw', true],
	['throw', 'strong', true],
	['absent', 'strong', true],
	['throw', 'weak', false],
	['throw', 'short', false],
	['throw', 'false', false],
	['throw', 'throw', false],
	['throw', 'absent', false],
	['absent', 'absent', false],
]);
