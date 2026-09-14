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
 * cacti_http() reads the last response's status two ways: PHP 8.5's
 * http_get_last_response_headers(), and, when that is unavailable, the
 * predefined $http_response_header variable via cacti_http_fetch_legacy()
 * in lib/functions_http_legacy.php.
 *
 * The legacy path is exercised here against a real local socket, because
 * $http_response_header is populated by the engine's http:// stream wrapper
 * and cannot be produced by a stub. The modern path is exercised with
 * namespaced stubs for function_exists(), http_get_last_response_headers()
 * and file_get_contents(), so it runs on any PHP version regardless of
 * whether the interpreter actually has the 8.5 function, following the
 * pattern in RemoteDataCollectorReservedAddressTest.php.
 */

namespace CactiHttpResponseHeaderCompatTest;

/* Mirror cacti_http()'s own guard: load the legacy file only when this
 * interpreter lacks http_get_last_response_headers(), so running this suite
 * under PHP 8.5 never compiles the deprecated $http_response_header
 * reference either. */
$has_native_headers_api = \function_exists('http_get_last_response_headers');

if (!$has_native_headers_api) {
	require_once dirname(__DIR__, 2) . '/lib/functions_http_legacy.php';
}

test('the legacy fallback parses the status and body from a real HTTP response', function () use ($has_native_headers_api) {
	if ($has_native_headers_api) {
		$this->markTestSkipped('cacti_http_fetch_legacy() is only loaded when http_get_last_response_headers() is unavailable');
	}

	$work = sys_get_temp_dir() . '/cacti-http-legacy-' . bin2hex(random_bytes(6));
	mkdir($work, 0700);

	$serverScript = <<<'PHP'
<?php
$server = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_bind($server, '127.0.0.1', 0);
socket_listen($server, 1);
socket_getsockname($server, $addr, $port);
echo $port . "\n";
fflush(STDOUT);

socket_set_option($server, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 10, 'usec' => 0));
$client = socket_accept($server);
socket_read($client, 65536);

$response = "HTTP/1.1 201 Created\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok";
socket_write($client, $response);
socket_close($client);
socket_close($server);
PHP;

	file_put_contents($work . '/server.php', $serverScript);

	$descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
	$process     = proc_open(array(\PHP_BINARY, $work . '/server.php'), $descriptors, $pipes);

	$port = (int) trim(fgets($pipes[1]));

	$ctx = stream_context_create(array(
		'http' => array(
			'method'          => 'GET',
			'timeout'         => 5,
			'follow_location' => 0,
			'max_redirects'   => 0,
			'ignore_errors'   => true,
			'header'          => "Accept: */*\r\nConnection: close\r\n",
		),
	));

	list($body, $headers) = \cacti_http_fetch_legacy("http://127.0.0.1:$port/", $ctx);

	proc_close($process);
	foreach ($pipes as $p) {
		if (is_resource($p)) {
			fclose($p);
		}
	}

	expect($body)->toBe('ok');
	expect($headers)->not->toBeEmpty();
	expect($headers[0])->toContain('201');
});

function function_exists($name) {
	if ($name === 'http_get_last_response_headers') {
		return true;
	}

	return \function_exists($name);
}

function http_get_last_response_headers() {
	return array('HTTP/1.1 201 Created', 'Content-Type: text/plain');
}

function file_get_contents($url, $use_include_path = false, $context = null) {
	return 'ok';
}

function read_config_option($opt) {
	return 'off';
}

if (!function_exists(__NAMESPACE__ . '\cacti_http')) {
	$source = \file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
	preg_match('/^function cacti_http\(.*?^}\n/ms', $source, $match);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $match[0]);
}

test('the modern path uses http_get_last_response_headers() when it exists', function () {
	$status = 0;
	$body   = cacti_http('http://example.invalid/', 5, [], $status);

	expect($status)->toBe(201);
	expect($body)->toBe('ok');
});
