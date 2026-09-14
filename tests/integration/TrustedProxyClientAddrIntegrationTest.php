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
 * Integration harness for get_client_addr() and the session cookie Secure
 * decision behind a reverse proxy.
 *
 * The unit tests call the extracted functions in process. These boot the PHP
 * built-in web server on tests/integration/fixtures/client_addr_fixture.php
 * and send real HTTP requests, so REMOTE_ADDR and the HTTP_* keys come from
 * the SAPI rather than a hand-built $_SERVER. The connecting peer is always
 * 127.0.0.1.
 *
 * With $trusted_proxies unset the results must match release/1.2.31. With
 * it set, forwarded headers count only from a trusted peer and the address
 * is the right-most hop that is not a trusted proxy.
 */

/* ---- harness helpers ---- */

function _tp_find_free_port() {
	$sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
	if ($sock === false) {
		throw new RuntimeException("could not allocate free port: {$errstr}");
	}
	$name = stream_socket_get_name($sock, false);
	fclose($sock);
	$parts = explode(':', $name);
	return (int) end($parts);
}

/**
 * Launch php -S with the given settings, JSON encoded into the environment.
 * A null $trusted_proxies leaves the variable out, so the setting is unset.
 * The caller stops the server with _tp_stop_server().
 */
function _tp_start_server($proxy_headers, $trusted_proxies = null) {
	$port    = _tp_find_free_port();
	$docroot = realpath(__DIR__ . '/fixtures');
	$router  = 'client_addr_fixture.php';

	$php_bin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
	$cmd = escapeshellarg($php_bin)
		. ' -S 127.0.0.1:' . (int)$port
		. ' -t ' . escapeshellarg($docroot)
		. ' ' . escapeshellarg($router);

	$descriptors = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);

	$env = $_ENV;
	$env['CLIENT_ADDR_PROXY_HEADERS'] = json_encode($proxy_headers);
	unset($env['CLIENT_ADDR_TRUSTED_PROXIES']);
	if ($trusted_proxies !== null) {
		$env['CLIENT_ADDR_TRUSTED_PROXIES'] = json_encode($trusted_proxies);
	}

	$proc = proc_open($cmd, $descriptors, $pipes, $docroot, $env);
	if (!is_resource($proc)) {
		throw new RuntimeException('proc_open failed for php -S');
	}

	$deadline = microtime(true) + 3.0;
	$ready = false;
	while (microtime(true) < $deadline) {
		$probe = @stream_socket_client('tcp://127.0.0.1:' . (int)$port, $errno, $errstr, 0.2);
		if ($probe !== false) {
			fclose($probe);
			$ready = true;
			break;
		}
		usleep(50000);
	}

	if (!$ready) {
		proc_terminate($proc, 9);
		proc_close($proc);
		throw new RuntimeException('php -S failed to start on port ' . $port);
	}

	return array(
		'proc'  => $proc,
		'port'  => $port,
		'pipes' => $pipes,
	);
}

function _tp_stop_server($server) {
	if (!is_array($server) || empty($server['proc'])) {
		return;
	}
	if (is_resource($server['proc'])) {
		proc_terminate($server['proc'], 15);
		foreach ($server['pipes'] as $p) {
			if (is_resource($p)) {
				fclose($p);
			}
		}
		proc_close($server['proc']);
	}
}

/**
 * GET the fixture with the given request headers and return its decoded
 * JSON answer.
 */
function _tp_fetch($port, array $headers = array()) {
	$ch = curl_init('http://127.0.0.1:' . (int)$port . '/');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 5);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$body = curl_exec($ch);
	if ($body === false) {
		$err = curl_error($ch);
		throw new RuntimeException('curl failed: ' . $err);
	}
	$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

	$data = json_decode($body, true);
	if ($status !== 200 || !is_array($data)) {
		throw new RuntimeException('unexpected fixture response ' . $status . ': ' . substr($body, 0, 400));
	}

	return $data;
}

function _tp_with_server($proxy_headers, $trusted_proxies, callable $run) {
	$server = _tp_start_server($proxy_headers, $trusted_proxies);
	try {
		return $run($server['port']);
	} finally {
		_tp_stop_server($server);
	}
}

/* ---- $trusted_proxies unset: release/1.2.31 behaviour ---- */

test('uses the peer address without proxy headers configured', function () {
	_tp_with_server(null, null, function ($port) {
		$resp = _tp_fetch($port, array('X-Forwarded-For: 198.51.100.66'));

		expect($resp['client_addr'])->toBe('127.0.0.1');
	});
});

test('takes the left-most forwarded address when trusted proxies are unset', function () {
	_tp_with_server(array('HTTP_X_FORWARDED_FOR'), null, function ($port) {
		expect(_tp_fetch($port)['client_addr'])->toBe('127.0.0.1');
		expect(_tp_fetch($port, array('X-Forwarded-For: 198.51.100.66, 203.0.113.9'))['client_addr'])->toBe('198.51.100.66');
		/* 1.2.31 does not trim entries, so " 203.0.113.9" is not an address */
		expect(_tp_fetch($port, array('X-Forwarded-For: bogus, 203.0.113.9'))['client_addr'])->toBe('127.0.0.1');
	});
});

test('keeps the release/1.2.31 cookie Secure decision when trusted proxies are unset', function () {
	foreach (array(null, true, array('HTTP_X_FORWARDED_FOR')) as $proxy_headers) {
		_tp_with_server($proxy_headers, null, function ($port) {
			foreach (array(array(), array('X-Forwarded-Proto: https'), array('X-Forwarded-Ssl: on')) as $headers) {
				$resp = _tp_fetch($port, $headers);

				expect($resp['cookie_secure'])->toBe($resp['cookie_secure_1_2_31'])
					->and($resp['cookie_secure'])->toBeFalse();
			}
		});
	}
});

/* ---- $trusted_proxies set to the connecting peer ---- */

test('takes the right-most untrusted hop from a trusted proxy', function () {
	_tp_with_server(array('HTTP_X_FORWARDED_FOR'), array('127.0.0.1'), function ($port) {
		expect(_tp_fetch($port)['client_addr'])->toBe('127.0.0.1');
		expect(_tp_fetch($port, array('X-Forwarded-For: 198.51.100.66, 203.0.113.9'))['client_addr'])->toBe('203.0.113.9');
		expect(_tp_fetch($port, array('X-Forwarded-For: 198.51.100.66, 203.0.113.9, 127.0.0.1'))['client_addr'])->toBe('203.0.113.9');
		expect(_tp_fetch($port, array('X-Forwarded-For: 203.0.113.9, bogus'))['client_addr'])->toBe('127.0.0.1');
	});
});

test('honours X-Forwarded-Proto from a trusted proxy', function () {
	_tp_with_server(array('HTTP_X_FORWARDED_FOR'), array('127.0.0.1'), function ($port) {
		expect(_tp_fetch($port)['cookie_secure'])->toBeFalse();
		expect(_tp_fetch($port, array('X-Forwarded-Proto: https'))['cookie_secure'])->toBeTrue();
		expect(_tp_fetch($port, array('X-Forwarded-Ssl: on'))['cookie_secure'])->toBeTrue();
	});
});

test('ignores forwarded headers when the peer is not a trusted proxy', function () {
	_tp_with_server(array('HTTP_X_FORWARDED_FOR'), array('10.0.0.1'), function ($port) {
		$resp = _tp_fetch($port, array('X-Forwarded-For: 198.51.100.66', 'X-Forwarded-Proto: https'));

		expect($resp['client_addr'])->toBe('127.0.0.1')
			->and($resp['cookie_secure'])->toBeFalse();
	});
});
