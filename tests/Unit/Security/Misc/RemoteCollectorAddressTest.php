<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Requests to a remote data collector go to the hostname stored on its poller
 * row. call_remote_data_collector() and cacti_http() run here from
 * lib/functions.php with name resolution and the HTTP fetch replaced, so the
 * test sees which address a request would reach and with which options.
 */

namespace RemoteCollectorAddressTest;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';
$root = dirname(__DIR__, 4);
if (!class_exists(\Kadupul\Platform\Contract\CollectorAddress::class) && is_file($root . '/src/Platform/Contract/CollectorAddress.php')) {
    require_once $root . '/src/Platform/Contract/CollectorAddress.php';
}
$source = \file_get_contents($root . '/lib/functions.php');
if ($source === false) {
    throw new \RuntimeException('Unable to read lib/functions.php');
}
foreach (array('call_remote_data_collector', 'cacti_http', 'is_ipaddress') as $name) {
    eval('namespace ' . __NAMESPACE__ . '; ' . test_php_function_source($source, $name)); // nosemgrep: php.lang.security.eval-use.eval-use
}

final class Fixture
{
    public static string $hostname = '';
    /** @var array<string, string> */
    public static array $dns = array();
    /** @var list<array{url: string, options: array<string, mixed>}> */
    public static array $fetches = array();
    /** @var list<string> */
    public static array $log = array();
    public static string $scheme = 'https';
}

function db_fetch_cell_prepared(string $sql, array $params = array()): string
{
    return Fixture::$hostname;
}
function gethostbyname(string $name): string
{
    return Fixture::$dns[$name] ?? $name;
}
function get_url_type(): string
{
    return Fixture::$scheme;
}
function get_default_contextoption(): array
{
    return array('http' => array('timeout' => 5, 'ignore_errors' => true));
}
function read_config_option(string $name): string
{
    return '';
}
function debounce_run_notification(string $key): bool
{
    return false;
}
function admin_email(string $subject, string $message): void {}
function __(string $text, mixed ...$arguments): string
{
    return $text;
}
function cacti_log(string $message, bool $output = false, string $environ = 'CMDPHP'): void
{
    Fixture::$log[] = $message;
}
function file_get_contents(string $url, bool $include = false, mixed $context = null): string
{
    Fixture::$fetches[] = array('url' => $url, 'options' => is_resource($context) ? stream_context_get_options($context) : array());

    return 'body';
}

beforeEach(function () {
    Fixture::$hostname = '';
    Fixture::$dns = array('collector.example.test' => '10.20.30.40', 'metadata.example.test' => '169.254.169.254', 'local.example.test' => '127.0.0.1');
    Fixture::$fetches = array();
    Fixture::$log = array();
    Fixture::$scheme = 'https';
});

test('a collector whose address is reserved is not contacted', function (string $hostname) {
    Fixture::$hostname = $hostname;

    expect(call_remote_data_collector(2, '/remote_agent.php?action=ping'))->toBe('')
        ->and(Fixture::$fetches)->toBe(array())
        ->and(implode("\n", Fixture::$log))->toContain('SECURITY: Refusing Remote Data Collector request');
})->with(array(
    'metadata address' => '169.254.169.254',
    'name resolving to the metadata address' => 'metadata.example.test',
    'IPv4-mapped metadata address' => '::ffff:169.254.169.254',
    'bracketed IPv4-mapped metadata address' => '[::ffff:169.254.169.254]',
    'NAT64 metadata address' => '64:ff9b::a9fe:a9fe',
    '6to4 metadata address' => '2002:a9fe:a9fe::1',
    'IPv6 link-local' => 'fe80::1',
    'this network' => '0.0.0.0',
    'unspecified IPv6' => '::',
    'header injection' => "collector.example.test\r\nX-Injected: 1",
    'space in name' => 'collector example',
));

test('a collector request connects to the checked address without following redirects', function () {
    Fixture::$hostname = 'collector.example.test';

    expect(call_remote_data_collector(2, '/kadupul/remote_agent.php?action=ping'))->toBe('body');

    $fetch = Fixture::$fetches[0];
    expect($fetch['url'])->toBe('https://10.20.30.40/kadupul/remote_agent.php?action=ping')
        ->and($fetch['options']['http']['follow_location'])->toBe(0)
        ->and($fetch['options']['http']['max_redirects'])->toBe(0)
        ->and($fetch['options']['http']['header'])->toBe('Host: collector.example.test')
        ->and($fetch['options']['ssl']['peer_name'])->toBe('collector.example.test');
});

test('loopback, private and bracketed IPv6 collectors stay reachable', function (string $hostname, string $url) {
    Fixture::$hostname = $hostname;

    expect(call_remote_data_collector(2, '/remote_agent.php'))->toBe('body')
        ->and(Fixture::$fetches[0]['url'])->toBe($url);
})->with(array(
    'loopback name' => array('local.example.test', 'https://127.0.0.1/remote_agent.php'),
    'IPv6 loopback' => array('::1', 'https://[::1]/remote_agent.php'),
    'bracketed unique local' => array('[fd00::5]', 'https://[fd00::5]/remote_agent.php'),
    'private address' => array('192.168.1.20', 'https://192.168.1.20/remote_agent.php'),
));

test('cacti_http connects to a given address and keeps the name for Host and TLS', function () {
    $status = 0;
    cacti_http('https://collector.example.test:8443/kadupul/remote_agent.php?action=runquery&host_id=7', 15, array('collector.example.test'), $status, '10.20.30.40');

    $fetch = Fixture::$fetches[0];
    expect($fetch['url'])->toBe('https://10.20.30.40:8443/kadupul/remote_agent.php?action=runquery&host_id=7')
        ->and($fetch['options']['http']['header'])->toContain("Host: collector.example.test:8443\r\n")
        ->and($fetch['options']['ssl']['peer_name'])->toBe('collector.example.test')
        ->and($fetch['options']['http']['follow_location'])->toBe(0);
});

test('cacti_http refuses a connect address that does not match an address host', function () {
    $status = 0;

    expect(cacti_http('https://[fd00::5]/x', 15, array('[fd00::5]'), $status, 'fd00::6'))->toBeFalse()
        ->and(cacti_http('https://collector.example.test/x', 15, array(), $status, 'not-an-address'))->toBeFalse()
        ->and(Fixture::$fetches)->toBe(array());

    cacti_http('https://[fd00::5]/x', 15, array('[fd00::5]'), $status, 'fd00::5');
    expect(Fixture::$fetches[0]['url'])->toBe('https://[fd00::5]/x');
});
