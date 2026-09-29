<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
$globalSource = file_get_contents($root . '/include/global.php');
require_once $root . '/lib/functions.php';
require_once $root . '/lib/html_utility.php';

test('forced HTTPS redirect uses the configured server name instead of the Host header', function () {
    expect(cacti_build_https_redirect_url('kadupul.example', '/cacti/host.php?id=12'))
        ->toBe('https://kadupul.example/cacti/host.php?id=12');
});

test('forced HTTPS redirect passes SERVER_NAME, not HTTP_HOST, to its URL builder', function () use ($globalSource) {
    $start = strpos($globalSource, 'cacti_build_https_redirect_url(');
    expect($start)->not->toBeFalse();

    $fragment = substr($globalSource, $start, 200);
    expect($fragment)->toContain("\$_SERVER['SERVER_NAME']");
    expect($fragment)->not->toContain("\$_SERVER['HTTP_HOST']");
});

test('forced HTTPS redirect rejects an invalid configured authority', function () {
    expect(cacti_build_https_redirect_url("kadupul.example\r\nLocation: https://attacker.example", '/cacti/'))
        ->toBe('');
});

test('forced HTTPS redirect keeps request targets local', function () {
    expect(cacti_build_https_redirect_url('kadupul.example', '//attacker.example/path', '/cacti/'))
        ->toBe('https://kadupul.example/cacti/');
});

test('forced HTTPS redirect brackets IPv6 server names', function () {
    expect(cacti_build_https_redirect_url('2001:db8::1', '/cacti/'))
        ->toBe('https://[2001:db8::1]/cacti/');
});
