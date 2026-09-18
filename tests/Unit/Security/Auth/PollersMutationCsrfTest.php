<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

$runController = function ($method, $action, $selected = true, $site = 'same-origin') use ($root) {
    $program = <<<'PHP'
namespace PollersControllerRuntime;

$_SERVER['REQUEST_METHOD']      = $argv[1];
$_SERVER['SERVER_NAME']         = 'cacti.example';
$_SERVER['HTTP_SEC_FETCH_SITE'] = $argv[4];
$GLOBALS['action']              = $argv[2];
$GLOBALS['selected']            = $argv[3] === '1';

$source = file_get_contents(getcwd() . '/pollers.php');
$csrf   = file_get_contents(getcwd() . '/include/csrf.php');

preg_match('/^switch \(get_request_var\(\'action\'\)\) \{(?P<body>.*?)^}$/ms', $source, $match);
if (empty($match['body'])) {
    exit(2);
}

$helpers = '';
foreach (array('csrf_require_post', 'csrf_request_is_cross_site', 'csrf_request_host_matches', 'csrf_strip_host_port') as $name) {
    if (preg_match('/^function ' . $name . '\(.*?^}\R/ms', $csrf, $helper)) {
        $helpers .= $helper[0];
    }
}

function get_request_var($name) { return $name === 'action' ? $GLOBALS['action'] : ''; }
function get_nfilter_request_var($name) { return get_request_var($name); }
function isset_request_var($name) { return $name === 'selected_items' ? $GLOBALS['selected'] : true; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function form_save() { echo "HANDLER:save\n"; }
function form_actions() { echo "HANDLER:actions\n"; }
function test_database_connection() { echo "HANDLER:ping\n"; }
function top_header() {}
function poller_edit() {}
function pollers() {}
function bottom_footer() {}

eval('namespace PollersControllerRuntime; ' . $helpers);
eval("namespace PollersControllerRuntime; switch (get_request_var('action')) {" . $match['body'] . '}');
echo 'accepted';
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $method, $action, $selected ? '1' : '0', $site),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $stdout, $stderr);
};

test('data collector bulk actions refuse any GET, even from the same site', function () use ($runController) {
    foreach (array('same-origin', 'none', 'cross-site') as $site) {
        foreach (array('GET', 'HEAD', 'PUT') as $method) {
            list($exit, $stdout, $stderr) = $runController($method, 'actions', true, $site);

            expect($exit)->toBe(0, $stderr)
                ->and($stdout)->toContain('HEADER:Allow: POST')
                ->and($stdout)->not->toContain('HANDLER:')
                ->and($stdout)->not->toContain('accepted');
        }
    }
});

test('data collector bulk actions still run on POST', function () use ($runController) {
    list($exit, $stdout, $stderr) = $runController('POST', 'actions');

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('HANDLER:actions')
        ->and($stdout)->not->toContain('Allow: POST');
});

test('the data collector confirmation step without selected items is left to render', function () use ($runController) {
    list($exit, $stdout, $stderr) = $runController('GET', 'actions', false);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('HANDLER:actions')
        ->and($stdout)->not->toContain('Allow: POST');
});
