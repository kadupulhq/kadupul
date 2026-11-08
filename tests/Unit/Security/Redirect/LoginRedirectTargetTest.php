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
 * The "return to referer" login option falls back to REQUEST_URI when the
 * server sets no REDIRECT_URL and the browser sends no Referer. That target
 * must stay on this site, while ordinary request paths keep redirecting to
 * themselves exactly as in 1.2.31.
 */

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

function login_redirect_target_probe(string $request_uri): array {
	$process = proc_open(
		array(PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__, 3) . '/fixtures/auth-login-redirect-target.php', dirname(__DIR__, 4)),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);

	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start the login redirect fixture');
	}

	fwrite($pipes[0], json_encode(array('request_uri' => $request_uri), JSON_THROW_ON_ERROR));
	fclose($pipes[0]);

	$output = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0);
	expect($error)->toBe('');

	return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('a request target naming another host sends the user to the console', function (string $request_uri): void {
	expect(login_redirect_target_probe($request_uri))->toBe(array(
		'headers' => array('Location: /cacti/index.php'),
		'realm'   => array('index.php'),
	));
})->with(array(
	'protocol relative'             => array('//evil.example/%2e%2e/cacti/index.php'),
	'encoded protocol relative'     => array('%2F%2Fevil.example/cacti/index.php'),
	'tab between the slashes'       => array('/%09/evil.example/cacti/index.php'),
	'absolute form request target'  => array('https://evil.example/cacti/index.php'),
));

test('an ordinary request path still redirects back to itself', function (string $request_uri, string $location, string $page): void {
	expect(login_redirect_target_probe($request_uri))->toBe(array(
		'headers' => array('Location: ' . $location),
		'realm'   => array($page),
	));
})->with(array(
	'graph page with action'  => array('/cacti/graph_view.php?action=tree', '/cacti/graph_view.php?action=tree', 'graph_view.php'),
	'login action is removed' => array('/cacti/graph_view.php', '/cacti/graph_view.php', 'graph_view.php'),
	'encoded filter'          => array('/cacti/host.php?filter=a%20b', '/cacti/host.php?filter=a b', 'host.php'),
	'site at the web root'    => array('/index.php', '/index.php', 'index.php'),
));

test('validate_local_redirect_path accepts only a path on this site', function (mixed $url, string $expected): void {
	$source = file_get_contents(__DIR__ . '/../../../../lib/html_utility.php');
	expect($source)->not->toBeFalse();

	if (!function_exists('validate_local_redirect_path')) {
		eval(test_php_function_source($source, 'validate_local_redirect_path'));
	}

	expect(validate_local_redirect_path($url, 'fallback.php'))->toBe($expected);
})->with(array(
	'site path'             => array('/cacti/graph_view.php?action=tree', '/cacti/graph_view.php?action=tree'),
	'root'                  => array('/', '/'),
	'empty'                 => array('', 'fallback.php'),
	'not a string'          => array(null, 'fallback.php'),
	'relative page'         => array('graph_view.php', 'fallback.php'),
	'protocol relative'     => array('//evil.example/', 'fallback.php'),
	'slash backslash'       => array('/\\evil.example/', 'fallback.php'),
	'scheme'                => array('https://evil.example/', 'fallback.php'),
	'javascript scheme'     => array('javascript:alert(1)', 'fallback.php'),
	'leading space'         => array(' //evil.example/', 'fallback.php'),
	'embedded tab'          => array("/\t/evil.example/", 'fallback.php'),
	'embedded line break'   => array("/cacti/\nindex.php", 'fallback.php'),
));
