<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 4) . '/lib/functions.php';
require_once dirname(__DIR__, 4) . '/lib/html_utility.php';

test('forced HTTPS redirects use the server configured name', function () {
	expect(cacti_build_https_redirect_url(
		'monitor.example',
		'/cacti/graph.php?id=1',
		'/cacti/'
	))->toBe('https://monitor.example/cacti/graph.php?id=1');
});

test('forced HTTPS redirects support IPv6 server names', function () {
	expect(cacti_build_https_redirect_url(
		'2001:db8::1',
		'/cacti/',
		'/cacti/'
	))->toBe('https://[2001:db8::1]/cacti/');
});

test('forced HTTPS redirects reject invalid server names', function () {
	expect(cacti_build_https_redirect_url(
		"monitor.example\r\nLocation: https://evil.example",
		'/cacti/',
		'/cacti/'
	))->toBe('');
});

test('redirect validation ignores a spoofed Host header', function () {
	$server = $_SERVER;

	try {
		$_SERVER['SERVER_NAME'] = 'monitor.example';
		$_SERVER['HTTP_HOST']   = 'evil.example';

		expect(validate_redirect_url('https://evil.example/admin', '/cacti/'))->toBe('/cacti/');
		expect(validate_redirect_url('https://monitor.example/graph.php?id=1', '/cacti/'))->toBe('/graph.php?id=1');
	} finally {
		$_SERVER = $server;
	}
});

test('forced HTTPS redirects follow any valid Host header when the server has no usable name', function () {
	foreach (array('_', '', '*', '*.example.com', '.example.com', '~^cacti\.') as $server_name) {
		expect(cacti_build_https_redirect_url($server_name, '/cacti/index.php', '/cacti/', 'cacti.example.com'))
			->toBe('https://cacti.example.com/cacti/index.php');
	}

	expect(cacti_build_https_redirect_url('_', '/cacti/', '/cacti/', 'cacti_host'))
		->toBe('https://cacti_host/cacti/');

	expect(cacti_build_https_redirect_url('', '/cacti/', '/cacti/', '[2001:db8::1]:8443'))
		->toBe('https://[2001:db8::1]:8443/cacti/');
});

test('forced HTTPS redirects keep the port of a Host header that matches the server name', function () {
	expect(cacti_build_https_redirect_url('cacti.example.com', '/cacti/index.php', '/cacti/', 'cacti.example.com:8080'))
		->toBe('https://cacti.example.com:8080/cacti/index.php');

	expect(cacti_build_https_redirect_url('Cacti.Example.com', '/cacti/', '/cacti/', 'cacti.example.COM:8443'))
		->toBe('https://cacti.example.COM:8443/cacti/');
});

test('forced HTTPS redirects use the server name, without the port, for a Host header that names another host', function () {
	expect(cacti_build_https_redirect_url('monitor.example', '/cacti/index.php', '/cacti/', 'evil.example:8080'))
		->toBe('https://monitor.example/cacti/index.php');

	expect(cacti_build_https_redirect_url('monitor.example', '/cacti/', '/cacti/', 'monitor.example.evil.example'))
		->toBe('https://monitor.example/cacti/');
});

test('forced HTTPS redirects accept a Host header listed in trusted_hosts', function () {
	$trusted = array('cacti.example.com', 'CACTI.other.example');

	expect(cacti_build_https_redirect_url('cacti.internal', '/cacti/', '/cacti/', 'cacti.example.com:8443', $trusted))
		->toBe('https://cacti.example.com:8443/cacti/');

	expect(cacti_build_https_redirect_url('cacti.internal', '/cacti/', '/cacti/', 'cacti.other.example', $trusted))
		->toBe('https://cacti.other.example/cacti/');

	expect(cacti_build_https_redirect_url('cacti.internal', '/cacti/', '/cacti/', 'evil.example', $trusted))
		->toBe('https://cacti.internal/cacti/');
});

test('forced HTTPS redirects compare IPv6 and IPv4 Host headers without brackets or port', function () {
	expect(cacti_build_https_redirect_url('2001:db8::1', '/cacti/', '/cacti/', '[2001:DB8::1]:8443'))
		->toBe('https://[2001:DB8::1]:8443/cacti/');

	expect(cacti_build_https_redirect_url('cacti.internal', '/cacti/', '/cacti/', '[2001:db8::1]', array('[2001:db8::1]')))
		->toBe('https://[2001:db8::1]/cacti/');

	expect(cacti_build_https_redirect_url('cacti.internal', '/cacti/', '/cacti/', '[2001:db8::1]:8443', array('2001:db8::1')))
		->toBe('https://[2001:db8::1]:8443/cacti/');

	expect(cacti_build_https_redirect_url('cacti.internal', '/cacti/', '/cacti/', '[2001:db8::2]:8443', array('2001:db8::1')))
		->toBe('https://cacti.internal/cacti/');

	expect(cacti_build_https_redirect_url('192.0.2.10', '/cacti/', '/cacti/', '192.0.2.10:8080'))
		->toBe('https://192.0.2.10:8080/cacti/');
});

test('forced HTTPS redirects keep the request URI byte for byte as 1.2.31 did', function () {
	$uris = array(
		'/cacti/graph_view.php?rfilter=a%7Cb',
		'/cacti/host.php?rfilter=R%26D%23x&page=2',
		'/cacti/graphs.php?filter=%E2%9C%93&graph_template_id=-1',
		'/cacti/../cacti/index.php',
	);

	foreach ($uris as $uri) {
		/* 1.2.31: header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) */
		expect(cacti_build_https_redirect_url('cacti.example.com', $uri, '/cacti/', 'cacti.example.com'))
			->toBe('https://cacti.example.com' . $uri);
	}
});

test('forced HTTPS redirects fall back to the server name for a malformed or missing Host header', function () {
	$hosts = array(
		'',
		'bad host',
		'a..b',
		'-cacti.example.com',
		'cacti.example.com:99999',
		'cacti.example.com:',
		'user@evil.example',
		'evil.example/path',
		'evil.example\\path',
		'[2001:db8::zz]',
		str_repeat('a', 64) . '.example',
	);

	foreach ($hosts as $host) {
		expect(cacti_build_https_redirect_url('monitor.example', '/cacti/', '/cacti/', $host))
			->toBe('https://monitor.example/cacti/');

		expect(cacti_build_https_redirect_url('_', '/cacti/', '/cacti/', $host))->toBe('');
	}
});

test('forced HTTPS redirects refuse header injection through the Host header or the URI', function () {
	expect(cacti_build_https_redirect_url('_', '/cacti/', '/cacti/', "cacti.example.com\r\nSet-Cookie: x=1"))
		->toBe('');

	expect(cacti_build_https_redirect_url('monitor.example', '/cacti/', '/cacti/', "monitor.example\r\nSet-Cookie: x=1"))
		->toBe('https://monitor.example/cacti/');

	$uris = array(
		"/cacti/\r\nSet-Cookie: x=1",
		"/cacti/\n",
		"/cacti/\0",
		'//evil.example/cacti/',
		'/\\evil.example',
		'/\\/evil.example',
		'http://evil.example/cacti/',
		'cacti/index.php',
		'/cacti/ index.php',
	);

	foreach ($uris as $uri) {
		expect(cacti_build_https_redirect_url('cacti.example.com', $uri, '/cacti/', 'cacti.example.com'))
			->toBe('https://cacti.example.com/cacti/');
	}
});

test('forced HTTPS bootstrap passes the Host header and trusted hosts, and forbids caching the redirect', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/include/global.php');

	expect($source)->toContain("\$_SERVER['REQUEST_URI'] ?? '',\n\t\t\t\t\$config['url_path'],\n\t\t\t\t\$_SERVER['HTTP_HOST'] ?? '',\n\t\t\t\t\$config['trusted_hosts'] ?? array()\n")
		->and($source)->toMatch("/header\\('Cache-Control: no-store'\\);\\s+header\\('Vary: Host'\\);\\s+header\\('Location: ' \\. \\\$location\\);/")
		->and($source)->toContain("if (isset(\$trusted_hosts) && is_array(\$trusted_hosts)) {\n\t\$config['trusted_hosts'] = \$trusted_hosts;");

	$dist = file_get_contents(dirname(__DIR__, 4) . '/include/config.php.dist');

	expect($dist)->toContain("//\$trusted_hosts = array('cacti.example.com');");
});
