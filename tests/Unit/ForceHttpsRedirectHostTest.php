<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$root = dirname(__DIR__, 2);
$globalSource = file_get_contents($root . '/include/global.php');

function buildHttpsRedirectInIsolatedProcess(string $serverName, string $requestUri, string $defaultPath = '/'): string
{
    $root = dirname(__DIR__, 2);
    $script = 'require ' . var_export($root . '/lib/functions.php', true) . ';'
        . 'require ' . var_export($root . '/lib/html_utility.php', true) . ';'
        . 'echo json_encode(cacti_build_https_redirect_url('
        . var_export($serverName, true) . ', '
        . var_export($requestUri, true) . ', '
        . var_export($defaultPath, true)
        . '), JSON_THROW_ON_ERROR);';
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    expect(is_resource($process))->toBeTrue();

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0);
    expect($output)->toBeString();

    return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
}

test('forced HTTPS redirect uses the configured server name instead of the Host header', function () {
    expect(buildHttpsRedirectInIsolatedProcess('kadupul.example', '/cacti/host.php?id=12'))
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
    expect(buildHttpsRedirectInIsolatedProcess("kadupul.example\r\nLocation: https://attacker.example", '/cacti/'))
        ->toBe('');
});

test('forced HTTPS redirect keeps request targets local', function () {
    expect(buildHttpsRedirectInIsolatedProcess('kadupul.example', '//attacker.example/path', '/cacti/'))
        ->toBe('https://kadupul.example/cacti/');
});

test('forced HTTPS redirect brackets IPv6 server names', function () {
    expect(buildHttpsRedirectInIsolatedProcess('2001:db8::1', '/cacti/'))
        ->toBe('https://[2001:db8::1]/cacti/');
});
