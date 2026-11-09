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
 * The "return to referer" login option redirects to REDIRECT_URL, the Referer
 * or REQUEST_URI, all client supplied, after removing the login action and
 * adding newtheme=1. The value sent must stay on this site after those edits,
 * while ordinary targets keep redirecting as in 1.2.31.
 */

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

function login_redirect_target_probe(array $input): array {
	$process = proc_open(
		array(PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__, 3) . '/fixtures/auth-login-redirect-target.php', dirname(__DIR__, 4)),
		array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
		$pipes
	);

	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start the login redirect fixture');
	}

	fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
	fclose($pipes[0]);

	$output = stream_get_contents($pipes[1]);
	$error  = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	expect(proc_close($process))->toBe(0);
	expect($error)->toBe('');

	return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('a target that names another host sends the user to the console', function (array $input): void {
	expect(login_redirect_target_probe($input))->toBe(array(
		'headers' => array('Location: /cacti/index.php'),
		'realm'   => array('index.php'),
	));
})->with(array(
	'protocol relative request'    => array(array('request_uri' => '//evil.example/%2e%2e/cacti/index.php')),
	'encoded protocol relative'    => array(array('request_uri' => '%2F%2Fevil.example/cacti/index.php')),
	'tab between the slashes'      => array(array('request_uri' => '/%09/evil.example/cacti/index.php')),
	'absolute form request target' => array(array('request_uri' => 'https://evil.example/cacti/index.php')),
));

/* 1.2.31 validated these and then removed '?action=login', leaving '//evil.example/' */
test('removing the login action cannot turn a local target into another host', function (array $input): void {
	expect(login_redirect_target_probe($input + array('url_path' => '/')))->toBe(array(
		'headers' => array('Location: /?action=login/evil.example/'),
		'realm'   => array(''),
	));
})->with(array(
	'request URI' => array(array('request_uri' => '/?action=login/evil.example/')),
	'referer'     => array(array('referer' => 'http://unit.test/?action=login/evil.example/')),
));

test('ordinary targets still redirect as in 1.2.31', function (array $input, string $location, string $page): void {
	expect(login_redirect_target_probe($input))->toBe(array(
		'headers' => array('Location: ' . $location),
		'realm'   => array($page),
	));
})->with(array(
	'graph page with action'      => array(array('request_uri' => '/cacti/graph_view.php?action=tree'), '/cacti/graph_view.php?action=tree', 'graph_view.php'),
	'login action is removed'     => array(array('request_uri' => '/cacti/graph_view.php?action=login'), '/cacti/graph_view.php', 'graph_view.php'),
	'login action added by sanitize_uri' => array(array('request_uri' => '/cacti/graph_view.php'), '/cacti/graph_view.php', 'graph_view.php'),
	'encoded filter'              => array(array('request_uri' => '/cacti/host.php?filter=a%20b'), '/cacti/host.php?filter=a b', 'host.php'),
	'site at the web root'        => array(array('request_uri' => '/index.php'), '/index.php', 'index.php'),
	'same site referer'           => array(array('referer' => 'http://unit.test/cacti/host.php?id=3&action=edit'), '/cacti/host.php?id=3&action=edit', 'host.php'),
	'later login action is kept'  => array(array('referer' => 'http://unit.test/cacti/host.php?id=3&action=login'), '/cacti/host.php?id=3&action=login', 'host.php'),
	'foreign referer'             => array(array('referer' => 'https://evil.example/cacti/host.php'), '/cacti/index.php', 'index.php'),
	'theme change'                => array(array('request_uri' => '/cacti/host.php?id=3', 'newtheme' => true), '/cacti/host.php?id=3&newtheme=1', 'host.php'),
));

/* 1.2.31 left these malformed: '/cacti/graph_view.php&newtheme=1' and '/cacti/host.php&id=3' */
test('removing the login action keeps the remaining query well formed', function (array $input, string $location, string $page): void {
	expect(login_redirect_target_probe($input))->toBe(array(
		'headers' => array('Location: ' . $location),
		'realm'   => array($page),
	));
})->with(array(
	'login action and theme change' => array(array('request_uri' => '/cacti/graph_view.php?action=login', 'newtheme' => true), '/cacti/graph_view.php?newtheme=1', 'graph_view.php'),
	'login action before a filter'  => array(array('request_uri' => '/cacti/host.php?action=login&id=3'), '/cacti/host.php?id=3', 'host.php'),
));

test('validate_local_redirect_path accepts only a target on this site', function (mixed $url, string $expected): void {
	$source = file_get_contents(__DIR__ . '/../../../../lib/html_utility.php');
	expect($source)->not->toBeFalse();

	if (!function_exists('validate_local_redirect_path')) {
		eval(test_php_function_source($source, 'validate_local_redirect_path'));
	}

	expect(validate_local_redirect_path($url, 'fallback.php'))->toBe($expected);
})->with(array(
	'site path'             => array('/cacti/graph_view.php?action=tree', '/cacti/graph_view.php?action=tree'),
	'root'                  => array('/', '/'),
	'relative page'         => array('graph_view.php?action=tree', 'graph_view.php?action=tree'),
	'colon in the query'    => array('graph_view.php?date=10:00', 'graph_view.php?date=10:00'),
	'empty'                 => array('', 'fallback.php'),
	'not a string'          => array(null, 'fallback.php'),
	'protocol relative'     => array('//evil.example/', 'fallback.php'),
	'slash backslash'       => array('/\\evil.example/', 'fallback.php'),
	'leading backslash'     => array('\\\\evil.example/', 'fallback.php'),
	'scheme'                => array('https://evil.example/', 'fallback.php'),
	'javascript scheme'     => array('javascript:alert(1)', 'fallback.php'),
	'leading space'         => array(' //evil.example/', 'fallback.php'),
	'embedded tab'          => array("/\t/evil.example/", 'fallback.php'),
	'embedded line break'   => array("/cacti/\nindex.php", 'fallback.php'),
));
