<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * data_debug.php?purge=1 truncates the check table, and carries no action name
 * for the guard in include/global.php to match. The Purge button reaches it by
 * a same-site GET, which still works; anything else is refused.
 */

namespace DataDebugPurgeGuardTest;

/**
 * Runs the data_debug.php purge guard in a child process, because it exits.
 *
 * @param string                $method  The request method.
 * @param array<string, mixed>  $request The request variables.
 * @param array<string, mixed>  $post    The POST variables.
 * @param array<string, string> $server  Request headers as $_SERVER keys.
 *
 * @return string The response code, or 'pass' when the guard let it through.
 */
function run_guard($method, array $request, array $post = array(), array $server = array()) {
	$root  = dirname(__DIR__, 4);
	$page  = file_get_contents($root . '/data_debug.php');
	$csrf  = file_get_contents($root . '/include/csrf.php');
	$start = strpos($page, "/* Purge carries no action name");
	$end   = strpos($page, 'validate_request_vars();');

	if ($start === false || $end === false || $start > $end) {
		throw new \RuntimeException('Unable to extract the purge guard from data_debug.php.');
	}

	$helpers = '';

	foreach (array('csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $matches) === 1) {
			$helpers .= $matches[0];
		}
	}

	$functions = file_get_contents($root . '/lib/functions.php');

	foreach (array('sanitize_uri', 'is_urlencoded') as $name) {
		if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $functions, $matches) !== 1) {
			throw new \RuntimeException('Missing URI helper');
		}

		$helpers .= $matches[0];
	}

	$script = '<?php
		function isset_request_var($v) { return isset($_REQUEST[$v]); }
		' . $helpers . '
		$_SERVER  = ' . var_export($server + array('REQUEST_METHOD' => $method, 'SERVER_NAME' => 'cacti.example'), true) . ';
		$_REQUEST = ' . var_export($request, true) . ';
		$_POST    = ' . var_export($post, true) . ';
		$passed   = false;
		register_shutdown_function(function () use (&$passed) {
			print $passed ? "pass" : (string) http_response_code();
		});
		' . substr($page, $start, $end - $start) . '
		$passed = true;';

	$file = tempnam(sys_get_temp_dir(), 'purge-guard');
	file_put_contents($file, $script);

	try {
		return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
	} finally {
		unlink($file);
	}
}

test('the Purge button still works', function () {
	expect(run_guard('GET', array('purge' => '1', 'debug' => '-1')))->toBe('pass');
	expect(run_guard('GET', array('purge' => '1'), array(), array('HTTP_SEC_FETCH_SITE' => 'same-origin')))->toBe('pass');
	expect(run_guard('POST', array('purge' => '1'), array('__csrf_magic' => 'token')))->toBe('pass');
});

test('a page without purge is left alone', function () {
	expect(run_guard('GET', array('action' => 'view'), array(), array('HTTP_ORIGIN' => 'https://attacker.example')))->toBe('pass');
});

test('another site cannot purge the checks', function () {
	$headers = array(
		'Sec-Fetch-Site cross-site'   => array('HTTP_SEC_FETCH_SITE' => 'cross-site'),
		'mismatched Origin'           => array('HTTP_ORIGIN' => 'https://attacker.example'),
		'Origin null'                 => array('HTTP_ORIGIN' => 'null'),
		'mismatched Referer'          => array('HTTP_REFERER' => 'https://attacker.example/cacti/host.php'),
		'Referer on a lookalike host' => array('HTTP_REFERER' => 'https://cacti.example.attacker.example/'),
	);

	foreach ($headers as $name => $server) {
		expect(run_guard('GET', array('purge' => '1'), array(), $server))->toBe('405', $name);
	}
});

test('a token-less POST is refused', function () {
	expect(run_guard('POST', array('purge' => '1')))->toBe('405');
	expect(run_guard('DELETE', array('purge' => '1')))->toBe('405');
});
