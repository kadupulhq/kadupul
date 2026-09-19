<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 4);

/* Runs the managers.php dispatch switch in a child PHP process, because the
   POST guard calls exit. */
$runController = function ($method, $action, $purge = false, $site = 'same-origin') use ($root) {
    $program = <<<'PHP'
namespace ManagersControllerRuntime;

$_SERVER['REQUEST_METHOD']      = $argv[1];
$_SERVER['SERVER_NAME']         = 'cacti.example';
$_SERVER['HTTP_SEC_FETCH_SITE'] = $argv[4];
$GLOBALS['action']              = $argv[2];
$GLOBALS['purge']               = $argv[3] === '1';

$source = file_get_contents(getcwd() . '/managers.php');
$csrf   = file_get_contents(getcwd() . '/include/csrf.php');

preg_match('/switch \(get_request_var\(\'action\'\)\) \{(?P<body>.*?)^}$/ms', $source, $match);
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
function isset_request_var($name) { return $name === 'purge' && $GLOBALS['purge']; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function form_save() { echo "HANDLER:save\n"; }
function form_actions() { echo "HANDLER:actions\n"; }
function manager_edit() { echo 'HANDLER:edit' . ($GLOBALS['purge'] ? ':purge' : '') . "\n"; }
function manager() { echo "HANDLER:list\n"; }
function top_header() {}
function bottom_footer() {}

eval('namespace ManagersControllerRuntime; ' . $helpers);
eval("namespace ManagersControllerRuntime; switch (get_request_var('action')) {" . $match['body'] . '}');
echo 'accepted';
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $method, $action, $purge ? '1' : '0', $site),
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

test('notification receiver bulk actions refuse any GET, even from the same site', function () use ($runController) {
    foreach (array('same-origin', 'none', 'cross-site') as $site) {
        foreach (array('GET', 'HEAD', 'PUT') as $method) {
            list($exit, $stdout, $stderr) = $runController($method, 'actions', false, $site);

            expect($exit)->toBe(0, $stderr)
                ->and($stdout)->toContain('HEADER:Allow: POST')
                ->and($stdout)->not->toContain('HANDLER:')
                ->and($stdout)->not->toContain('accepted');
        }
    }
});

test('notification receiver bulk actions still run on POST', function () use ($runController) {
    list($exit, $stdout, $stderr) = $runController('POST', 'actions');

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('HANDLER:actions')
        ->and($stdout)->not->toContain('Allow: POST');
});

test('notification log purge refuses any GET, even from the same site', function () use ($runController) {
    foreach (array('same-origin', 'cross-site') as $site) {
        foreach (array('GET', 'HEAD') as $method) {
            list($exit, $stdout, $stderr) = $runController($method, 'edit', true, $site);

            expect($exit)->toBe(0, $stderr)
                ->and($stdout)->toContain('HEADER:Allow: POST')
                ->and($stdout)->not->toContain('HANDLER:')
                ->and($stdout)->not->toContain('accepted');
        }
    }
});

test('notification log viewing by GET and purge by POST still work', function () use ($runController) {
    list($exit, $stdout, $stderr) = $runController('GET', 'edit');

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('HANDLER:edit')
        ->and($stdout)->not->toContain('Allow: POST');

    list($exit, $stdout, $stderr) = $runController('POST', 'edit', true);

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('HANDLER:edit:purge')
        ->and($stdout)->not->toContain('Allow: POST');
});

test('the purge button posts with the csrf token', function () use ($root) {
    $source = file_get_contents($root . '/managers.php');

    expect($source)->toContain("\$('#purge').on('click', function() {")
        ->and($source)->toContain("loadPageUsingPost('managers.php', {")
        ->and($source)->toContain('__csrf_magic: csrfMagicToken');
});
