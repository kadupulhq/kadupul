<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

/*
 * State-changing actions that pages still send as same-origin GET must not
 * run from a GET another site started, or by a method that carries no token.
 */

function cross_site_guard_run(string $method, string $query, array $headers = array()): array
{
    $root = dirname(__DIR__, 4);
    $global = file_get_contents($root . '/include/global.php');

    $program = <<<'PHP'
$config = array('include_path' => $argv[1] . '/include', 'is_web' => false);
$_SERVER['REQUEST_METHOD'] = $argv[2];
$_SERVER['SERVER_NAME'] = 'kadupul.example.com';
foreach (json_decode($argv[4], true) as $name => $value) {
    $_SERVER[$name] = $value;
}
parse_str($argv[3], $_REQUEST);
register_shutdown_function(function () { echo http_response_code() ?: 200; });
require $argv[1] . '/lib/html_utility.php';
require $argv[1] . '/include/csrf.php';
cacti_require_post_actions(array('save', 'update_data', 'changepassword'));
if (function_exists('csrf_refuse_cross_site_actions')) {
    csrf_refuse_cross_site_actions();
}
echo 'DISPATCHED:';
PHP;

    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $method, $query, json_encode($headers)), $coverage_dir),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    return array('stdout' => $stdout, 'stderr' => $stderr, 'global_calls_guard' => str_contains($global, 'csrf_refuse_cross_site_actions();'));
}

test('include/global.php runs the guard for every web request', function () {
    expect(cross_site_guard_run('GET', '')['global_calls_guard'])->toBeTrue();
});

test('a cross-site GET cannot run a state-changing action', function (string $query, array $headers) {
    $result = cross_site_guard_run('GET', $query, $headers);

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('405');
})->with(array(
    'Sec-Fetch-Site cross-site' => array('action=delete_node&id=4', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'Sec-Fetch-Site same-site' => array('action=gt_remove&id=4', array('HTTP_SEC_FETCH_SITE' => 'same-site')),
    'foreign Origin' => array('action=lock&id=1', array('HTTP_ORIGIN' => 'https://attacker.example.net')),
    'foreign Referer' => array('action=query_reload&id=1', array('HTTP_REFERER' => 'https://attacker.example.net/page')),
    'confirmed bulk action' => array('action=actions&selected_items=a%3A0%3A%7B%7D', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'table purge' => array('action=purge', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'table purge, foreign Origin' => array('action=purge', array('HTTP_ORIGIN' => 'https://attacker.example.net')),
    'rule quick edit' => array('action=qedit&id=1&name=x', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
));

test('a state-changing action by a method other than GET or POST is refused', function (string $method) {
    expect(cross_site_guard_run($method, 'action=remove&id=4')['stdout'])->toBe('405');
})->with(array('HEAD', 'PUT', 'DELETE'));

test('same-site and header-less GET requests still reach the page', function (string $query, array $headers) {
    $result = cross_site_guard_run('GET', $query, $headers);

    expect($result['stderr'])->toBe('')
        ->and($result['stdout'])->toBe('DISPATCHED:200');
})->with(array(
    'same-origin XHR' => array('action=create_node&id=4', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
    'typed or bookmarked' => array('action=lock&id=1', array('HTTP_SEC_FETCH_SITE' => 'none')),
    'script without headers' => array('action=query_reload&id=1', array()),
    'own Referer with port' => array('action=unlock&id=1', array('HTTP_REFERER' => 'https://kadupul.example.com:8443/tree.php')),
    'own Origin' => array('action=item_remove&id=1', array('HTTP_ORIGIN' => 'https://KADUPUL.example.com')),
    'same-origin table purge' => array('action=purge', array('HTTP_SEC_FETCH_SITE' => 'same-origin')),
    'own Referer rule quick edit' => array('action=qedit&id=1', array('HTTP_REFERER' => 'https://kadupul.example.com/automation_graph_rules.php')),
    'read-only action cross-site' => array('action=edit&id=1', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
    'bulk confirmation page cross-site' => array('action=actions', array('HTTP_SEC_FETCH_SITE' => 'cross-site')),
));

test('host comparison ignores ports and keeps bare IPv6 addresses whole', function (string $server, string $host, bool $expected) {
    $root = dirname(__DIR__, 4);
    $program = '$config = array("include_path" => $argv[1] . "/include", "is_web" => false);'
        . 'require $argv[1] . "/lib/html_utility.php"; require $argv[1] . "/include/csrf.php";'
        . 'echo csrf_strip_host_port($argv[2]) === $argv[3] ? "same" : "different";';
    $process = proc_open(
        child_coverage_command(array(PHP_BINARY, '-r', $program, $root, $server, $host), $coverage_dir),
        array(1 => array('pipe', 'w')),
        $pipes
    );
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    proc_close($process);
    child_coverage_collect($coverage_dir);

    expect($stdout)->toBe($expected ? 'same' : 'different');
})->with(array(
    'name with port' => array('kadupul.example.com:8443', 'kadupul.example.com', true),
    'bracketed IPv6 with port' => array('[2001:db8::1]:8443', '[2001:db8::1]', true),
    'bare IPv6' => array('2001:db8::1', '2001:db8::1', true),
    'bare IPv6 is not shortened' => array('2001:db8::1', '2001:db8:', false),
));
