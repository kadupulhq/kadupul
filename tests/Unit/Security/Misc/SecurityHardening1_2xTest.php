<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

$authProfileSource  = file_get_contents(__DIR__ . '/../../../../auth_profile.php');
$functionsSource    = file_get_contents(__DIR__ . '/../../../../lib/functions.php');
$htmlUtilitySource  = file_get_contents(__DIR__ . '/../../../../lib/html_utility.php');
$databaseSource     = file_get_contents(__DIR__ . '/../../../../lib/database.php');

// M-1: JS context injection in auth_profile.php

test('auth_profile currentTheme uses json_encode not bare print', function () use ($authProfileSource) {
	expect($authProfileSource)->not->toContain("var currentTheme = '<?php print get_selected_theme()");
	expect($authProfileSource)->toContain('json_encode((string) get_selected_theme())');
});

test('auth_profile currentLang uses json_encode not bare print', function () use ($authProfileSource) {
	expect($authProfileSource)->not->toContain("var currentLang  = '<?php print read_config_option('user_language')");
	expect($authProfileSource)->toContain("json_encode((string) read_config_option('user_language'))");
});

test('auth_profile authMethod uses json_encode not bare print', function () use ($authProfileSource) {
	expect($authProfileSource)->not->toContain("var authMethod   = '<?php print read_config_option('auth_method')");
	expect($authProfileSource)->toContain("json_encode((string) read_config_option('auth_method'))");
});

// M-2: sanitize_uri double-decode removed

test('redirect validation rejects encoded protocol-relative and external destinations', function () {
    require_once dirname(__DIR__, 4) . '/lib/functions.php';
    require_once dirname(__DIR__, 4) . '/lib/html_utility.php';
    foreach (array('//evil.example', '%2f%2fevil.example', '%252f%252fevil.example', 'https%3a%2f%2fevil.example') as $url) {
        expect(validate_redirect_url($url, 'index.php'))->toBe('index.php');
    }
});

// M-3/M-4: validate_redirect_url trusts HTTP_HOST only when SERVER_NAME is empty and it is listed in $trusted_hosts

test('validate_redirect_url does not trust an unlisted HTTP_HOST', function () {
	require_once __DIR__ . '/../../../../lib/functions.php';
	require_once __DIR__ . '/../../../../lib/html_utility.php';

	$saved_server = $_SERVER;
	$had_config   = array_key_exists('config', $GLOBALS);
	$saved_config = $GLOBALS['config'] ?? null;

	try {
		unset($_SERVER['SERVER_PORT']);
		$GLOBALS['config'] = array('trusted_hosts' => array('cacti.example.com'));

		/* SERVER_NAME empty, attacker-chosen Host header naming the target's host */
		$_SERVER['SERVER_NAME'] = '';
		$_SERVER['HTTP_HOST']   = 'evil.example';
		expect(validate_redirect_url('https://evil.example/phish', '/cacti/'))->toBe('/cacti/');

		/* SERVER_NAME empty, Host header listed in $trusted_hosts */
		$_SERVER['HTTP_HOST'] = 'cacti.example.com';
		expect(validate_redirect_url('https://cacti.example.com/cacti/host.php?id=3', '/cacti/'))->toBe('/cacti/host.php?id=3');

		/* SERVER_NAME set: the Host header is never used, even when listed */
		$_SERVER['SERVER_NAME'] = 'monitor.example';
		expect(validate_redirect_url('https://cacti.example.com/cacti/host.php?id=3', '/cacti/'))->toBe('/cacti/');
		expect(validate_redirect_url('https://monitor.example/cacti/host.php?id=3', '/cacti/'))->toBe('/cacti/host.php?id=3');
	} finally {
		$_SERVER = $saved_server;

		if ($had_config) {
			$GLOBALS['config'] = $saved_config;
		} else {
			unset($GLOBALS['config']);
		}
	}
});

test('redirect validation rejects slash normalization bypasses', function () {
    expect(validate_redirect_url(chr(92) . chr(92) . 'evil.example', 'index.php'))->toBe('index.php');
});

// H-4: db_dump_data wraps credential values with cacti_escapeshellarg

test('db_dump_data escapes all command arguments with cacti_escapeshellarg', function () use ($databaseSource) {
	$start = strpos($databaseSource, 'function db_dump_data(');
	expect($start)->not->toBeFalse();

	$body = substr($databaseSource, $start, 2000);
	expect($body)->toContain('cacti_escapeshellarg(');
});

test('db_dump_data passes password via environment not command line', function () use ($databaseSource) {
	$start = strpos($databaseSource, 'function db_dump_data(');
	$body = substr($databaseSource, $start, 2000);
	// Password must appear in env array, not in the $command array
	expect($body)->toContain("'MYSQL_PWD'");
	// --password flag must not be appended to the command array
	expect($body)->not->toContain("'--password'");
	expect($body)->not->toContain('"-p"');
});

// H-5: check_auth_cookie lockout logic must deny locked, not deny unlocked
//
// auth_process_lockout_check() returns true when the account IS locked.
// The condition must be truthy (deny when locked), not === false (deny when unlocked).

$authSource = file_get_contents(__DIR__ . '/../../../../lib/auth.php');

test('check_auth_cookie uses truthy lockout check not inverted === false', function () use ($authSource) {
	$start = strpos($authSource, 'function check_auth_cookie(');
	expect($start)->not->toBeFalse();

	$body = substr($authSource, $start, 3000);
	// The correct form: if (auth_process_lockout_check(...)) { return false; }
	expect($body)->toContain('if (auth_process_lockout_check(');
	// The broken form must not be present inside check_auth_cookie
	expect($body)->not->toContain('auth_process_lockout_check($user_info[\'username\'], $user_info[\'realm\']) === false');
});

// H-6: cacti_auth_transition must not touch user_auth_cache (no cookie rotation)
//
// On 1.2.x cookie rotation belongs to set_auth_cookie / check_auth_cookie.
// Putting it in cacti_auth_transition causes a double rotation: check_auth_cookie
// rotates T1->T2, then the transition reads the stale $_COOKIE (still T1),
// its UPDATE matches nothing, and it queues a third cookie T3.  The browser
// gets T3 while the DB holds T2, breaking remember-me on the next visit.

test('cacti_auth_transition does not rotate the remember-me cookie', function () use ($authSource) {
	$start = strpos($authSource, 'function cacti_auth_transition(');
	expect($start)->not->toBeFalse();

	$end   = strpos($authSource, "\nfunction ", $start + 1);
	$body  = $end !== false ? substr($authSource, $start, $end - $start) : substr($authSource, $start, 3000);

	expect($body)->not->toContain('UPDATE user_auth_cache');
	expect($body)->not->toContain('cacti_cookie_session_set(');
});

test('check_auth_cookie calls set_auth_cookie after the lockout check', function () use ($authSource) {
	$start = strpos($authSource, 'function check_auth_cookie(');
	expect($start)->not->toBeFalse();

	$end  = strpos($authSource, "\nfunction ", $start + 1);
	$body = $end !== false ? substr($authSource, $start, $end - $start) : substr($authSource, $start, 3000);

	$lockoutPos    = strpos($body, 'auth_process_lockout_check(');
	$setCookiePos  = strpos($body, 'set_auth_cookie(');

	expect($lockoutPos)->not->toBeFalse('auth_process_lockout_check not found in check_auth_cookie');
	expect($setCookiePos)->not->toBeFalse('set_auth_cookie not found in check_auth_cookie');
	expect($lockoutPos)->toBeLessThan($setCookiePos, 'lockout check must come before set_auth_cookie');
});
