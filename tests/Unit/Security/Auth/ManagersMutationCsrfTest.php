<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

$root = dirname(__DIR__, 4);

/* Runs the managers.php dispatch switch in a child PHP process, because the
   POST guard calls exit. */
$runController = function ($method, $action, $purge = false) use ($root) {
    $program = <<<'PHP'
namespace ManagersControllerRuntime;

$_SERVER['REQUEST_METHOD'] = $argv[1];
$GLOBALS['action']         = $argv[2];
$GLOBALS['purge']          = $argv[3] === '1';

$source = file_get_contents(getcwd() . '/managers.php');

preg_match('/switch \(get_request_var\(\'action\'\)\) \{(?P<body>.*?)^}$/ms', $source, $match);
preg_match('/^function managers_require_post\(.*?^}\n/ms', $source, $helper);
if (empty($match['body'])) {
    exit(2);
}

function get_request_var($name) { return $name === 'action' ? $GLOBALS['action'] : ''; }
function isset_request_var($name) { return $name === 'purge' && $GLOBALS['purge']; }
function cacti_log($message, $output = false, $facility = '') { echo 'LOG:' . $facility . ':' . $message . "\n"; }
function header($value) { echo 'HEADER:' . $value . "\n"; }
function form_save() { echo "HANDLER:save\n"; }
function form_actions() { echo "HANDLER:actions\n"; }
function manager_edit() { echo 'HANDLER:edit' . ($GLOBALS['purge'] ? ':purge' : '') . "\n"; }
function manager() { echo "HANDLER:list\n"; }
function top_header() {}
function bottom_footer() {}

if (!empty($helper[0])) {
    eval('namespace ManagersControllerRuntime; ' . $helper[0]);
}
eval("namespace ManagersControllerRuntime; switch (get_request_var('action')) {" . $match['body'] . '}');
echo 'accepted';
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, $method, $action, $purge ? '1' : '0'),
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

test('notification receiver bulk actions are refused unless the request is a POST', function () use ($runController) {
    foreach (array('GET', 'HEAD', 'PUT') as $method) {
        list($exit, $stdout, $stderr) = $runController($method, 'actions');

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain('LOG:AUTH:WARNING: Rejected non-POST request to managers.php?action=actions')
            ->and($stdout)->toContain('HEADER:Location: managers.php?header=false')
            ->and($stdout)->not->toContain('HANDLER:')
            ->and($stdout)->not->toContain('accepted');
    }
});

test('notification receiver bulk actions still run on POST', function () use ($runController) {
    list($exit, $stdout, $stderr) = $runController('POST', 'actions');

    expect($exit)->toBe(0, $stderr)
        ->and($stdout)->toContain('HANDLER:actions')
        ->and($stdout)->not->toContain('Rejected non-POST');
});
