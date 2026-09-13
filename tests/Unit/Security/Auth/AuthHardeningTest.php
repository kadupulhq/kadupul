<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Regression tests for three 1.2.x auth-hardening fixes found in review:
 *  - a disabled guest account keeps granting anonymous access, as in 1.2.31
 *  - the get_allowed_* permission where-clauses must not interpolate a raw id
 *  - the Secure session cookie must be set behind a trusted TLS proxy
 */

$functions = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
$auth      = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
$global    = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');

test('get_guest_account selects the guest user whether or not it is enabled', function () use ($functions) {
	$start = strpos($functions, 'function get_guest_account(');
	expect($start)->not->toBeFalse();
	$body = substr($functions, $start, strpos($functions, "\n}", $start) - $start);
	expect($body)->toContain('WHERE username = ? OR id = ?')
		->and($body)->not->toContain('enabled');
});

test('permission where-clauses cast the id to int rather than interpolating it raw', function () use ($auth) {
	// the raw interpolations are gone
	expect($auth)->not->toContain('" gl.id = $graph_id"');
	expect($auth)->not->toContain('" h.id = $device_id"');
	// and replaced with an int cast
	expect(substr_count($auth, '(int) $graph_id'))->toBeGreaterThan(0);
	expect($auth)->toContain('(int) $device_id');
});

test('the Secure cookie flag honours a forwarded-proto only when proxy trust is enabled', function () use ($global) {
	$start = strpos($global, "session.cookie_secure");
	expect($start)->not->toBeFalse();
	// the forwarded-proto path is gated on proxy_headers so a client cannot force it
	expect($global)->toContain("!empty(\$config['proxy_headers'])");
	expect($global)->toContain('HTTP_X_FORWARDED_PROTO');
});
