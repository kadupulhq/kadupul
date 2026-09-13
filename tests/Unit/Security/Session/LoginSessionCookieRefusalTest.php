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
 * auth_login.php refuses a login without the session cookie only when the
 * request posts a login form token. That combination means Cacti's own form
 * lost its cookie, which loops back to the login page in 1.2.31 as well. A
 * kiosk URL or a scripted login without a token proceeds as in 1.2.31.
 */

require_once dirname(__DIR__, 3) . '/Helpers/AuthEntryProbe.php';

function login_cookie_block(string $source) : ?string {
	$start = strpos($source, "if (\$auth_method != 2 && get_nfilter_request_var('action') == 'login'");

	if ($start === false) {
		return null;
	}

	$depth = 0;
	$len   = strlen($source);

	for ($i = strpos($source, '{', $start); $i < $len; $i++) {
		if ($source[$i] === '{') {
			$depth++;
		} elseif ($source[$i] === '}') {
			$depth--;

			if ($depth === 0) {
				return substr($source, $start, $i - $start + 1);
			}
		}
	}

	throw new RuntimeException('auth_login.php session cookie block is unbalanced');
}

function login_cookie_release_source() : ?string {
	static $source = false;

	if ($source === false) {
		$output = shell_exec('git -C ' . escapeshellarg(dirname(__DIR__, 4)) . ' show release/1.2.31:auth_login.php 2>/dev/null');
		$source = (is_string($output) && strpos($output, 'auth_get_username()') !== false) ? $output : null;
	}

	return $source;
}

/**
 * @param array<string, mixed> $request
 *
 * @return array{refused: bool, log: bool}
 */
function login_cookie_run(array $request) : array {
	$block = login_cookie_block(file_get_contents(dirname(__DIR__, 4) . '/auth_login.php'));

	if ($block === null) {
		throw new RuntimeException('auth_login.php session cookie block not found');
	}

	$child = <<<'PHP'
<?php
$scenario = json_decode(stream_get_contents(STDIN), true);

session_name('Cacti');

$_GET        = $scenario['get'] ?? array();
$_POST       = $scenario['post'] ?? array();
$_COOKIE     = $scenario['cookie'] ?? array();
$_REQUEST    = array_merge($_GET, $_POST);
$auth_method = $scenario['auth_method'] ?? 1;

function get_nfilter_request_var($name, $default = '') {
	return $_REQUEST[$name] ?? $default;
}

function cacti_session_cookie_failure($write_log = true) {
	print json_encode(array('refused' => true, 'log' => $write_log));
	exit;
}

PHP;

	$child .= $block . "\n\nprint json_encode(array('refused' => false, 'log' => false));\n";

	return cacti_test_run_php_source($child, $request);
}

/**
 * @return array<string, array<string, mixed>>
 */
function login_cookie_legitimate_requests() : array {
	return array(
		'kiosk GET with credentials and no cookies' => array(
			'get' => array('action' => 'login', 'login_username' => 'kiosk', 'login_password' => 'secret'),
		),
		'kiosk GET from a browser that keeps other cookies' => array(
			'get'    => array('action' => 'login', 'login_username' => 'kiosk', 'login_password' => 'secret'),
			'cookie' => array('cacti_remembers' => '', 'AWSALB' => 'x'),
		),
		'scripted POST without a form token' => array(
			'post' => array('action' => 'login', 'login_username' => 'svc', 'login_password' => 'secret'),
		),
		'form POST that returns the session cookie' => array(
			'post'   => array('action' => 'login', 'login_username' => 'alice', 'login_password' => 'secret', '__csrf_magic' => 'sid:abc,1'),
			'cookie' => array('Cacti' => 'session-id'),
		),
		'Web Basic request with a form token' => array(
			'auth_method' => 2,
			'post'        => array('action' => 'login', '__csrf_magic' => 'sid:abc,1'),
		),
		'page load without the login action' => array(
			'get' => array(),
		),
	);
}

test('a login that 1.2.31 let through is not refused for a missing session cookie', function () {
	foreach (login_cookie_legitimate_requests() as $name => $request) {
		expect(login_cookie_run($request)['refused'])->toBeFalse($name);
	}
});

test('a posted login form token without the session cookie is still refused', function () {
	$clean = login_cookie_run(array(
		'post' => array('action' => 'login', 'login_username' => 'alice', 'login_password' => 'secret', '__csrf_magic' => 'ip:abc,1'),
	));

	$other = login_cookie_run(array(
		'post'   => array('action' => 'login', 'login_username' => 'alice', 'login_password' => 'secret', '__csrf_magic' => 'ip:abc,1'),
		'cookie' => array('cacti_remembers' => ''),
	));

	/* only a request that returned some other cookie is logged, as before */
	expect($clean)->toBe(array('refused' => true, 'log' => false))
		->and($other)->toBe(array('refused' => true, 'log' => true));
});

test('1.2.31 had no session cookie refusal ahead of the login', function () {
	$source = login_cookie_release_source();

	expect(login_cookie_block($source))->toBeNull()
		->and($source)->not->toContain('cacti_session_cookie_failure');
})->skip(function () {
	return login_cookie_release_source() === null;
}, 'release/1.2.31 is not available in this clone');
