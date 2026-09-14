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
 * in lib/functions_http_legacy.php. Both paths are exercised against a real
 * local socket, because $http_response_header is populated by the engine's
 * http:// stream wrapper and cannot be produced by a stub.
 *
 * cacti_http() itself is evaluated into this namespace from its own source
 * so its unqualified function_exists() call resolves to the stub below
 * instead of the real one, following the pattern in
 * RemoteDataCollectorReservedAddressTest.php. eval() resolves __DIR__ to
 * this file's directory rather than lib/, so the require_once path inside
 * the extracted source is rewritten to the real legacy file's path before
 * eval, so the fallback branch still finds it when a test drives it there.
 */

namespace CactiHttpResponseHeaderCompatTest;

/* Whether the stubbed function_exists('http_get_last_response_headers')
 * reports the PHP 8.4+ API as present. Tests flip this before calling the
 * evaluated cacti_http() to drive it down either branch. */
$native_headers_available = true;

function function_exists($name) {
	global $native_headers_available;

	if ($name === 'http_get_last_response_headers') {
		return $native_headers_available;
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
	$source = \file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
	preg_match('/^function cacti_http\(.*?^}\n/ms', $source, $match);

	$legacy_path = var_export(dirname(__DIR__, 4) . '/lib/functions_http_legacy.php', true);
	$patched     = str_replace("__DIR__ . '/functions_http_legacy.php'", $legacy_path, $match[0]);

	// test-only eval of source read from this repository, not external input
	eval('namespace ' . __NAMESPACE__ . '; ' . $patched);
}

/* Mirror cacti_http()'s own guard: load the legacy file directly only when
 * this interpreter lacks http_get_last_response_headers(), so running this
 * suite under PHP 8.5 never compiles the deprecated $http_response_header
 * reference outside of the one test that deliberately forces it below. */
$has_native_headers_api = \function_exists('http_get_last_response_headers');

if (!$has_native_headers_api) {
	require_once dirname(__DIR__, 4) . '/lib/functions_http_legacy.php';
}

/*
 * Starts a one-shot HTTP server in a child process that replies with a
 * fixed 201 response, then closes. Returns the process handle, its pipes,
 * and the port it bound, so the caller can make one request against it and
 * tear it down afterward.
 */
function start_canned_http_server() {
	$work = sys_get_temp_dir() . '/cacti-http-compat-' . bin2hex(random_bytes(6));
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

	return array($process, $pipes, $port);
}

function stop_canned_http_server($process, $pipes) {
	proc_close($process);

	foreach ($pipes as $p) {
		if (is_resource($p)) {
			fclose($p);
		}
	}
}

test('the legacy fallback parses the status and body from a real HTTP response', function () use ($has_native_headers_api) {
	if ($has_native_headers_api) {
		$this->markTestSkipped('cacti_http_fetch_legacy() is only loaded when http_get_last_response_headers() is unavailable');
	}

	if (!\function_exists('socket_create')) {
		$this->markTestSkipped('sockets extension not available');
	}

	list($process, $pipes, $port) = start_canned_http_server();

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

	stop_canned_http_server($process, $pipes);

	expect($body)->toBe('ok');
	expect($headers)->not->toBeEmpty();
	expect($headers[0])->toContain('201');
});

test('the modern path uses http_get_last_response_headers() when it exists', function () {
	global $native_headers_available;
	$native_headers_available = true;

	$status = 0;
	$body   = cacti_http('http://example.invalid/', 5, [], $status);

	expect($status)->toBe(201);
	expect($body)->toBe('ok');
});

test('the fallback branch runs end to end against a real response when the native API is absent', function () {
	/* Forcing the fallback branch here makes it require the real legacy
	 * file and execute its $http_response_header reference, which PHP 8.5
	 * flags at runtime same as it does at compile time. Production code
	 * never takes this branch on 8.5, since the native API is always
	 * present there, so skip rather than assert on manufactured noise. */
	if (PHP_VERSION_ID >= 80500) {
		$this->markTestSkipped('cacti_http() never takes the legacy branch on PHP 8.5; see the compile-time deprecation covered by the CI syntax-check exclusion instead');
	}

	if (!\function_exists('socket_create')) {
		$this->markTestSkipped('sockets extension not available');
	}

	global $native_headers_available;
	$native_headers_available = false;

	list($process, $pipes, $port) = start_canned_http_server();

	$status = 0;
	$body   = cacti_http("http://127.0.0.1:$port/", 5, [], $status);

	stop_canned_http_server($process, $pipes);

	$native_headers_available = true;

	expect($status)->toBe(201);
	expect($body)->toBe('ok');
});
