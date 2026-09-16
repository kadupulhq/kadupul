<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
*/

require_once dirname(__DIR__, 4) . '/lib/functions.php';
require_once dirname(__DIR__, 4) . '/lib/html_utility.php';

test('validate_redirect_url allows safe local URLs', function () {
	expect(validate_redirect_url('index.php'))->toBe('index.php');
	expect(validate_redirect_url('/host.php?id=1'))->toBe('/host.php?id=1');
	expect(validate_redirect_url('host.php'))->toBe('host.php');
});

test('validate_redirect_url rejects protocol-relative URLs', function () {
	expect(validate_redirect_url('//evil.com'))->toBe('index.php');
});

test('validate_redirect_url rejects malicious schemes', function () {
	expect(validate_redirect_url('javascript:alert(1)'))->toBe('index.php');
	expect(validate_redirect_url('data:text/html,base64,...'))->toBe('index.php');
	expect(validate_redirect_url('vbscript:msgbox'))->toBe('index.php');
});

test('validate_redirect_url rejects external domains', function () {
	expect(validate_redirect_url('http://google.com'))->toBe('index.php');
	expect(validate_redirect_url('https://evil.com/cacti/index.php'))->toBe('index.php');
});

test('validate_redirect_url rejects CRLF injection', function () {
	expect(validate_redirect_url("/index.php\r\nSet-Cookie: pwned=1"))->toBe('index.php');
});

test('validate_redirect_url handles url-encoded input', function () {
	$encoded = urlencode('http://evil.com');
	expect(validate_redirect_url($encoded))->toBe('index.php');
	
	$encoded_local = urlencode('/index.php?id=1');
	expect(validate_redirect_url($encoded_local))->toBe('/index.php?id=1');
});


test('same-host IPv6 redirects accept bare and bracketed server names', function () {
    $saved = $_SERVER;
    try {
        foreach (array('::1', '[::1]', '[::1]:8443') as $host) {
            $_SERVER['SERVER_NAME'] = $host;
            expect(validate_redirect_url('http://[::1]/graphs.php'))->toBe('/graphs.php')
                ->and(validate_redirect_url('http://[::2]/graphs.php'))->toBe('index.php');
        }
    } finally {
        $_SERVER = $saved;
    }
});
